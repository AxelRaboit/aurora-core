<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Aurora\Module\Editorial\Poll\Repository\PollVoteRepository;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function intdiv;
use function max;
use function mb_strtoupper;
use function mb_substr;
use function mb_trim;
use function preg_replace;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function transliterator_transliterate;

use const DATE_ATOM;

/**
 * What the small self-contained zones draw: availability, opening hours, a
 * countdown, a business card and a social post.
 *
 * Kept out of GridViewBuilder, which batches queries across a whole grid.
 * None of these asks the database for anything but the pictures that batch
 * already fetched, so they only need the zone, its words and the page's
 * language - and a clock, because three of them are about now.
 */
final readonly class ZoneWidgetViews
{
    public function __construct(
        private TranslatorInterface $translator,
        // Optional: injected where the container has one, which is what lets
        // a test pin "now". A project without it reads the system clock.
        private ?ClockInterface $clock = null,
        private ?RequestStack $requestStack = null,
        private ?PollVoteRepository $pollVotes = null,
        private ?UrlGeneratorInterface $urlGenerator = null,
        private ?BookingSlotFinder $bookingSlots = null,
    ) {}

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed>                       $zone  the normalised layout zone
     * @param array<string, mixed>                       $held  its words in this language
     * @param Closure(?int): (array<string, mixed>|null) $media a picture of the library, prefetched
     *
     * @return array<string, mixed>|null
     */
    public function build(array $zone, array $held, string $locale, Closure $media, ?int $postId = null): ?array
    {
        $options = $zone['options'];

        return match ($zone['type']) {
            GridNormalizer::ZONE_AVAILABILITY => $this->availability($options, $held, $locale),
            GridNormalizer::ZONE_OPENING_HOURS => $this->openingHours($options, $held, $locale),
            GridNormalizer::ZONE_COUNTDOWN => $this->countdown($options, $held),
            GridNormalizer::ZONE_CONTACT_CARD => $this->contactCard($options, $held, $media($zone['mediaId'])),
            GridNormalizer::ZONE_SOCIAL_POST => $this->socialPost($options, $held, $locale, $media($zone['mediaId']), $media($options['socialAvatarId'])),
            GridNormalizer::ZONE_QR_CODE => $this->qrCode($zone, $options, $held, $media($options['qrLogoId'])),
            GridNormalizer::ZONE_CHART => $this->chart($options, $held, $locale),
            GridNormalizer::ZONE_EDITORIAL_CALENDAR => $this->editorialCalendar($options, $held, $locale),
            GridNormalizer::ZONE_PRICE_LIST => $this->priceList($held),
            GridNormalizer::ZONE_POLL => $this->poll($zone, $options, $held, $locale, $postId),
            GridNormalizer::ZONE_AUDIO => $this->chapters($held),
            GridNormalizer::ZONE_QUOTE_ESTIMATOR => $this->quoteEstimator($options, $held),
            GridNormalizer::ZONE_APPOINTMENT_BOOKING => $this->appointmentBooking($zone, $options, $held, $locale, $postId),
            // The button over a film playing behind a title. Its address is the
            // one the zone would give a provider's player, free once a film
            // of the library is picked instead.
            GridNormalizer::ZONE_VIDEO => $options['backgroundVideo'] && '' !== $held['label'] && null !== $held['url']
                ? ['label' => $held['label'], 'url' => $held['url']]
                : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>
     */
    private function availability(array $options, array $held, string $locale): array
    {
        $status = $options['availability'];
        $date = null === $options['availableFrom']
            ? ''
            : $this->longDate(new DateTimeImmutable($options['availableFrom']), $locale);

        // "Soon" without a date is "soon": the sentence drops the date rather
        // than printing an empty one.
        $key = 'soon' === $status && '' === $date ? 'soon_undated' : $status;

        return [
            'status' => $status,
            'label' => '' !== $held['label']
                ? $held['label']
                : $this->translator->trans('frontend.editorial.grid.availability.'.$key, ['%date%' => $date], 'messages', $locale),
            'note' => $held['caption'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function openingHours(array $options, array $held, string $locale): ?array
    {
        $timezone = new DateTimeZone($options['timezone']);
        $now = $this->now()->setTimezone($timezone);
        $today = $now->format('Y-m-d');
        $weekdayFormat = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $timezone, null, 'EEEE');

        $days = [];
        // 2024-01-01 was a Monday: a fixed week to ask the formatter for names.
        foreach (GridZoneOptions::WEEKDAYS as $index => $weekday) {
            $days[] = [
                'key' => $weekday,
                'name' => $this->capitalise((string) $weekdayFormat->format(new DateTimeImmutable(sprintf('2024-01-%02d', $index + 1)))),
                'ranges' => array_map(fn (array $range): string => $this->range($range, $locale), $options['hours'][$weekday]),
                'today' => $weekday === $this->weekday($now),
            ];
        }

        if ([] === array_filter($options['hours'])) {
            return null;
        }

        $closedToday = in_array($today, $options['closedDates'], true);
        $state = $this->openState($options, $now, $closedToday, $locale);

        $upcoming = array_values(array_filter(
            $options['closedDates'],
            static fn (string $day): bool => $day > $today,
        ));

        return [
            'days' => $days,
            'open' => $state['open'],
            'status' => $state['label'],
            'closedToday' => $closedToday,
            'closures' => array_map(
                fn (string $day): string => $this->longDate(new DateTimeImmutable($day), $locale),
                array_slice($upcoming, 0, 3),
            ),
            'note' => $held['caption'],
        ];
    }

    /**
     * Open or closed now, and the next change, in the zone's own timezone.
     *
     * @param array<string, mixed> $options
     *
     * @return array{open: bool, label: string}
     */
    private function openState(array $options, DateTimeImmutable $now, bool $closedToday, string $locale): array
    {
        $clock = $now->format('H:i');

        if (!$closedToday) {
            foreach ($options['hours'][$this->weekday($now)] as [$open, $close]) {
                if ($clock >= $open && $clock < $close) {
                    return [
                        'open' => true,
                        'label' => $this->translator->trans('frontend.editorial.grid.hours.open_until', ['%time%' => $this->time($close, $locale)], 'messages', $locale),
                    ];
                }

                if ($clock < $open) {
                    return [
                        'open' => false,
                        'label' => $this->translator->trans('frontend.editorial.grid.hours.opens_at', ['%time%' => $this->time($open, $locale)], 'messages', $locale),
                    ];
                }
            }
        }

        // The next day with a range that is not a closure, within a week.
        for ($ahead = 1; $ahead <= 7; ++$ahead) {
            $day = $now->modify(sprintf('+%d day', $ahead));
            $ranges = $options['hours'][$this->weekday($day)];
            if ([] === $ranges) {
                continue;
            }

            if (in_array($day->format('Y-m-d'), $options['closedDates'], true)) {
                continue;
            }

            $weekday = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $now->getTimezone(), null, 'EEEE')->format($day);

            return [
                'open' => false,
                'label' => 1 === $ahead
                    ? $this->translator->trans('frontend.editorial.grid.hours.opens_tomorrow', ['%time%' => $this->time($ranges[0][0], $locale)], 'messages', $locale)
                    : $this->translator->trans('frontend.editorial.grid.hours.opens_on', ['%day%' => (string) $weekday, '%time%' => $this->time($ranges[0][0], $locale)], 'messages', $locale),
            ];
        }

        return ['open' => false, 'label' => $this->translator->trans('frontend.editorial.grid.hours.closed', [], 'messages', $locale)];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function countdown(array $options, array $held): ?array
    {
        if (null === $options['countdownAt']) {
            return null;
        }

        $target = new DateTimeImmutable($options['countdownAt'], new DateTimeZone($options['timezone']));
        $left = max(0, $target->getTimestamp() - $this->now()->getTimestamp());

        return [
            // An absolute instant for the script, so the reader's own clock
            // and timezone do not move the target.
            'at' => $target->format(DATE_ATOM),
            'passed' => 0 === $left,
            'days' => intdiv($left, 86_400),
            'hours' => intdiv($left % 86_400, 3_600),
            'minutes' => intdiv($left % 3_600, 60),
            'seconds' => $left % 60,
            'title' => $held['label'],
            'after' => $held['caption'],
        ];
    }

    /**
     * @param array<string, mixed>      $options
     * @param array<string, mixed>      $held
     * @param array<string, mixed>|null $photo
     *
     * @return array<string, mixed>|null
     */
    private function contactCard(array $options, array $held, ?array $photo): ?array
    {
        if ('' === $options['contactName']) {
            return null;
        }

        return [
            'name' => $options['contactName'],
            'role' => $held['caption'],
            'phone' => $options['contactPhone'],
            // What `tel:` wants: the digits and the plus, nothing a person
            // adds to read a number aloud.
            'phoneHref' => preg_replace('/[^0-9+]/', '', $options['contactPhone']),
            'email' => $options['contactEmail'],
            'website' => $options['contactWebsite'],
            'websiteLabel' => null === $options['contactWebsite']
                ? ''
                : (string) preg_replace('#^https?://(www\.)?|/$#i', '', $options['contactWebsite']),
            'photo' => $photo,
            'vcard' => self::vcard($options, $held['caption']),
            'fileName' => $this->slug($options['contactName']).'.vcf',
        ];
    }

    /**
     * The contact file a phone saves in one tap, and the text the QR code
     * carries - the same card either way.
     *
     * @param array<string, mixed> $options
     */
    public static function vcard(array $options, string $role): string
    {
        $escape = static fn (string $value): string => str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $value);
        $parts = explode(' ', $options['contactName'], 2);

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.$escape($options['contactName']),
            'N:'.$escape($parts[1] ?? '').';'.$escape($parts[0]).';;;',
        ];

        if ('' !== $role) {
            $lines[] = 'TITLE:'.$escape($role);
        }

        if ('' !== $options['contactPhone']) {
            $lines[] = 'TEL;TYPE=CELL:'.preg_replace('/[^0-9+]/', '', $options['contactPhone']);
        }

        if ('' !== $options['contactEmail']) {
            $lines[] = 'EMAIL:'.$options['contactEmail'];
        }

        if (null !== $options['contactWebsite']) {
            $lines[] = 'URL:'.$options['contactWebsite'];
        }

        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines);
    }

    /**
     * @param array<string, mixed>      $options
     * @param array<string, mixed>      $held
     * @param array<string, mixed>|null $picture
     * @param array<string, mixed>|null $avatar
     *
     * @return array<string, mixed>|null
     */
    private function socialPost(array $options, array $held, string $locale, ?array $picture, ?array $avatar): ?array
    {
        if ('' === $options['socialName'] && '' === $held['caption'] && null === $picture) {
            return null;
        }

        return [
            'network' => $options['socialNetwork'],
            'name' => $options['socialName'],
            'handle' => $options['socialHandle'],
            'avatar' => $avatar,
            'picture' => $picture,
            'text' => $held['caption'],
            'likes' => $this->compact($options['socialLikes'], $locale),
            'comments' => $this->compact($options['socialComments'], $locale),
            'shares' => $this->compact($options['socialShares'], $locale),
            'date' => null === $options['socialDate'] ? '' : $this->longDate(new DateTimeImmutable($options['socialDate']), $locale),
        ];
    }

    /**
     * @param array<string, mixed>      $zone
     * @param array<string, mixed>      $options
     * @param array<string, mixed>      $held
     * @param array<string, mixed>|null $logo
     *
     * @return array<string, mixed>|null
     */
    private function qrCode(array $zone, array $options, array $held, ?array $logo): ?array
    {
        $url = (string) ($held['url'] ?? '');

        if ('' === $url) {
            return null;
        }

        // A phone that scans `/fr/contact` has no site to put it on: a path is
        // made whole against the page's own address.
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $host = $this->requestStack?->getMainRequest()?->getSchemeAndHttpHost();
            $url = null === $host ? $url : $host.$url;
        }

        return [
            'text' => $url,
            'label' => $held['label'],
            'logo' => $logo,
            'download' => $options['qrDownload'],
            'size' => $zone['size'],
            'fileName' => 'qr-code.png',
        ];
    }

    /** The lines of a table typed in the editor, blank ones dropped. */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', $text) ?: []), static fn (string $line): bool => '' !== $line));
    }

    /** A cell split on `|` or `;`, trimmed. */
    private function cells(string $line): array
    {
        return array_map(trim(...), preg_split('/\s*[|;]\s*/', $line) ?: []);
    }

    /** `1 234,5` or `1234.5` as a number; null when it is not one. */
    private function number(string $value): ?float
    {
        $clean = str_replace([' ', "\u{a0}", "\u{202f}"], '', $value);

        if (1 === preg_match('/^-?\d+,\d+$/', $clean)) {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * A chart drawn on the server as SVG geometry: no library in the page,
     * and a reader without JavaScript still sees it.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function chart(array $options, array $held, string $locale): ?array
    {
        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $numbers->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        $unit = $options['chartUnit'];
        $rows = [];

        foreach (array_slice($this->lines($held['code']), 0, 24) as $line) {
            $cells = $this->cells($line);
            $value = $this->number($cells[1] ?? '');
            if ('' === ($cells[0] ?? '')) {
                continue;
            }

            if (null === $value) {
                continue;
            }

            $rows[] = ['label' => $cells[0], 'value' => $value, 'valueLabel' => $numbers->format($value).('' === $unit ? '' : "\u{202f}".$unit)];
        }

        if ([] === $rows) {
            return null;
        }

        $values = array_column($rows, 'value');
        $max = max(max($values), 0.0);
        $min = min(min($values), 0.0);
        $span = $max - $min ?: 1.0;
        $type = $options['chartType'];

        foreach ($rows as $index => $row) {
            $rows[$index]['share'] = round(($row['value'] - $min) / $span * 100, 2);
        }

        $view = ['type' => $type, 'rows' => $rows, 'title' => $held['label'], 'note' => $held['caption']];

        if (in_array($type, ['line', 'growth'], true)) {
            // A 600 by 240 drawing, room left for the dots at the edges.
            $count = count($rows);
            $points = [];
            foreach ($rows as $index => $row) {
                $points[] = [
                    'x' => round(20 + ($count > 1 ? $index / ($count - 1) : 0.5) * 560, 1),
                    'y' => round(220 - ($row['value'] - $min) / $span * 200, 1),
                    'label' => $row['label'],
                    'valueLabel' => $row['valueLabel'],
                ];
            }

            $view['points'] = $points;
            $view['polyline'] = implode(' ', array_map(static fn (array $p): string => $p['x'].','.$p['y'], $points));
            $view['area'] = '20,220 '.$view['polyline'].' '.end($points)['x'].',220';
        }

        if ('growth' === $type && count($rows) > 1 && 0.0 !== $rows[0]['value']) {
            $change = ($rows[count($rows) - 1]['value'] - $rows[0]['value']) / abs($rows[0]['value']) * 100;
            $view['growth'] = ($change >= 0 ? '+' : '').$numbers->format(round($change)).' %';
            $view['growthUp'] = $change >= 0;
        }

        if ('donut' === $type) {
            // A circle of circumference 100, so each share is its own dash.
            $total = array_sum(array_map(abs(...), $values)) ?: 1.0;
            $offset = 0.0;
            foreach ($rows as $index => $row) {
                $length = abs($row['value']) / $total * 100;
                $rows[$index]['dash'] = round($length, 3);
                $rows[$index]['offset'] = round(25 - $offset, 3);
                $rows[$index]['percent'] = $numbers->format(round($length)).' %';
                $offset += $length;
            }

            $view['rows'] = $rows;
        }

        return $view;
    }

    /**
     * A month grid of planned posts, Monday first, with the list the phone
     * shows instead.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>
     */
    private function editorialCalendar(array $options, array $held, string $locale): array
    {
        $entries = [];

        foreach ($this->lines($held['code']) as $line) {
            $cells = $this->cells($line);
            if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $cells[0] ?? '')) {
                continue;
            }

            if (false === DateTimeImmutable::createFromFormat('!Y-m-d', $cells[0])) {
                continue;
            }

            $entries[] = [
                'date' => $cells[0],
                'network' => $cells[1] ?? '',
                'title' => implode(' | ', array_slice($cells, 2)),
                'tone' => $this->networkTone($cells[1] ?? ''),
            ];
        }

        $month = $options['calendarMonth'] ?? (null === ($entries[0]['date'] ?? null) ? $this->now()->format('Y-m') : mb_substr($entries[0]['date'], 0, 7));
        $first = new DateTimeImmutable($month.'-01');
        $start = $first->modify('-'.((int) $first->format('N') - 1).' days');
        $today = $this->now()->format('Y-m-d');
        $weekdays = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'EEE');
        $monthName = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'LLLL y');

        $weeks = [];
        $day = $start;
        do {
            $week = [];
            for ($i = 0; $i < 7; ++$i) {
                $key = $day->format('Y-m-d');
                $week[] = [
                    'day' => (int) $day->format('j'),
                    'inMonth' => $day->format('Y-m') === $month,
                    'today' => $key === $today,
                    'entries' => array_values(array_filter($entries, static fn (array $entry): bool => $entry['date'] === $key)),
                ];
                $day = $day->modify('+1 day');
            }

            $weeks[] = $week;
        } while ($day->format('Y-m') === $month);

        $inMonth = array_values(array_filter($entries, static fn (array $entry): bool => str_starts_with($entry['date'], $month)));
        usort($inMonth, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return [
            'month' => $this->capitalise((string) $monthName->format($first)),
            'weekdays' => array_map(fn (int $i): string => $this->capitalise(mb_rtrim((string) $weekdays->format(new DateTimeImmutable(sprintf('2024-01-%02d', $i + 1))), '.')), range(0, 6)),
            'weeks' => $weeks,
            'list' => array_map(fn (array $entry): array => [...$entry, 'dateLabel' => $this->longDate(new DateTimeImmutable($entry['date']), $locale)], $inMonth),
            'title' => $held['label'],
        ];
    }

    /** The colour a network is known by, so a month reads at a glance. */
    private function networkTone(string $network): string
    {
        return match (true) {
            str_contains(mb_strtolower($network), 'insta') => 'instagram',
            str_contains(mb_strtolower($network), 'linkedin') => 'linkedin',
            str_contains(mb_strtolower($network), 'facebook') => 'facebook',
            str_contains(mb_strtolower($network), 'tiktok') => 'tiktok',
            str_contains(mb_strtolower($network), 'youtube') => 'youtube',
            default => 'other',
        };
    }

    /**
     * Sections and lines of a menu or a price list.
     *
     * `# Entrées` opens a section; a line is `Name | price | tags | detail`,
     * the last two optional, the tags separated by commas.
     *
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function priceList(array $held): ?array
    {
        $sections = [];
        $current = null;

        foreach ($this->lines($held['code']) as $line) {
            if (str_starts_with($line, '#')) {
                $sections[] = ['title' => mb_trim(mb_substr($line, 1)), 'items' => []];
                $current = count($sections) - 1;

                continue;
            }

            if (null === $current) {
                $sections[] = ['title' => '', 'items' => []];
                $current = 0;
            }

            $cells = $this->cells($line);
            $sections[$current]['items'][] = [
                'name' => $cells[0],
                'price' => $cells[1] ?? '',
                'tags' => array_values(array_filter(array_map(trim(...), explode(',', $cells[2] ?? '')))),
                'detail' => $cells[3] ?? '',
            ];
        }

        $sections = array_values(array_filter($sections, static fn (array $section): bool => [] !== $section['items']));

        return [] === $sections ? null : ['sections' => $sections, 'title' => $held['label'], 'note' => $held['caption']];
    }

    /**
     * The days and slots a visitor may book, and where an answer goes.
     *
     * `null` outside a real page (no post id, the preview an author is
     * editing) rather than a booking form nobody can submit - there is
     * nothing wrong to say, there is simply no calendar to check yet.
     *
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function appointmentBooking(array $zone, array $options, array $held, string $locale, ?int $postId): ?array
    {
        if (in_array(null, [$this->bookingSlots, $postId, $this->urlGenerator], true)) {
            return null;
        }

        $planning = $this->bookingSlots->calendar($this->translator->trans('frontend.editorial.grid.booking.calendar_name', [], 'messages', $locale));
        $days = $this->bookingSlots->days($options, $planning, $locale);

        return [
            'title' => $held['label'],
            'note' => $held['caption'],
            'duration' => $options['slotDuration'],
            'days' => $days,
            'endpoint' => $this->urlGenerator->generate('editorial_booking_reserve', ['locale' => $locale, 'postId' => $postId, 'zoneId' => $zone['id']]),
        ];
    }

    /**
     * A calculator: a base price, options an author priced, and the total a
     * reader's ticks add up to - worked out again in the browser as each box
     * is ticked, so the server only has to say what each thing costs.
     *
     * `= 250` sets the base; any other non-empty line is `Label | price`, an
     * option a reader may tick. A price is read loosely (spaces, a comma for
     * the decimal point) and a line that is not one of the two shapes is
     * dropped, never guessed at.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function quoteEstimator(array $options, array $held): ?array
    {
        $base = 0.0;
        $items = [];

        foreach (self::lines($held['code']) as $line) {
            if (str_starts_with($line, '=')) {
                $base = self::number(mb_trim(mb_substr($line, 1))) ?? $base;

                continue;
            }

            $cells = self::cells($line);
            $price = self::number($cells[1] ?? '');
            if ('' === ($cells[0] ?? '')) {
                continue;
            }

            if (null === $price) {
                continue;
            }

            $items[] = ['label' => $cells[0], 'price' => $price];
        }

        if ([] === $items && 0.0 === $base) {
            return null;
        }

        return [
            'title' => $held['label'],
            'note' => $held['caption'],
            'base' => $base,
            'currency' => $options['quoteCurrency'],
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $options
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function poll(array $zone, array $options, array $held, string $locale, ?int $postId): ?array
    {
        $answers = array_slice($this->lines($held['code']), 0, 8);

        if ('' === $held['label'] || count($answers) < 2) {
            return null;
        }

        $tally = null !== $postId && $this->pollVotes instanceof PollVoteRepository ? $this->pollVotes->tally($postId, $zone['id']) : [];
        $total = array_sum($tally);

        return [
            'question' => $held['label'],
            'answers' => array_map(static fn (int $index, string $label): array => [
                'index' => $index,
                'label' => $label,
                'votes' => $tally[$index] ?? 0,
                'percent' => 0 === $total ? 0 : (int) round(($tally[$index] ?? 0) / $total * 100),
            ], array_keys($answers), $answers),
            'total' => $total,
            'showResults' => 'always' === $options['pollResults'],
            'endpoint' => null !== $postId && $this->urlGenerator instanceof UrlGeneratorInterface
                ? $this->urlGenerator->generate('editorial_poll_vote', ['locale' => $locale, 'postId' => $postId, 'zoneId' => $zone['id']])
                : null,
            'zoneKey' => sprintf('%s-%s', $postId ?? 'preview', $zone['id']),
        ];
    }

    /**
     * Chapters above a line of three dashes, the transcript below it.
     *
     * `04:12 Le matériel` is a chapter at four minutes twelve; a line that
     * does not start with a time is not one, and is ignored above the dashes.
     *
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    private function chapters(array $held): ?array
    {
        if ('' === mb_trim($held['code'])) {
            return null;
        }

        $parts = preg_split('/^\s*---\s*$/m', $held['code'], 2) ?: [$held['code']];
        $chapters = [];

        foreach ($this->lines($parts[0]) as $line) {
            if (1 !== preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\s+(.+)$/', $line, $m)) {
                continue;
            }

            $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
            $chapters[] = ['seconds' => $seconds, 'time' => mb_trim(explode(' ', $line, 2)[0]), 'label' => mb_trim($m[4])];
        }

        $transcript = mb_trim($parts[1] ?? '');

        return [] === $chapters && '' === $transcript ? null : ['chapters' => $chapters, 'transcript' => $transcript];
    }

    private function longDate(DateTimeImmutable $date, string $locale): string
    {
        $formatted = (string) new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC')->format($date);

        // ICU writes « 1 octobre » where French writes « 1er octobre ».
        return str_starts_with($locale, 'fr') ? (string) preg_replace('/^1(?=\s)/u', '1er', $formatted) : $formatted;
    }

    /** @param array{0: string, 1: string} $range */
    private function range(array $range, string $locale): string
    {
        return $this->time($range[0], $locale).' – '.$this->time($range[1], $locale);
    }

    /** `14:30` the way the page's language writes a time. */
    private function time(string $clock, string $locale): string
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $clock));

        return $this->translator->trans('frontend.editorial.grid.hours.time', [
            '%h%' => (string) $hours,
            '%hh%' => sprintf('%02d', $hours),
            '%mm%' => sprintf('%02d', $minutes),
        ], 'messages', $locale);
    }

    private function weekday(DateTimeImmutable $date): string
    {
        return GridZoneOptions::WEEKDAYS[(int) $date->format('N') - 1];
    }

    /** 1 234 567 as « 1,2 M », 12 400 as « 12 k »: how a network prints its counters. */
    private function compact(int $value, string $locale): string
    {
        if ($value < 1_000) {
            return (string) $value;
        }

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);

        [$divisor, $suffix] = $value >= 1_000_000 ? [1_000_000, 'M'] : [1_000, 'k'];
        $scaled = $value / $divisor;

        if ($scaled >= 10) {
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        }

        return $formatter->format($scaled)."\u{202f}".$suffix;
    }

    private function capitalise(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private function slug(string $value): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value) ?: $value;
        $slug = mb_trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');

        return '' === $slug ? 'contact' : $slug;
    }
}
