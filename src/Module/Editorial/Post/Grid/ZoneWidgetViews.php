<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
    public function build(array $zone, array $held, string $locale, Closure $media): ?array
    {
        $options = $zone['options'];

        return match ($zone['type']) {
            GridNormalizer::ZONE_AVAILABILITY => $this->availability($options, $held, $locale),
            GridNormalizer::ZONE_OPENING_HOURS => $this->openingHours($options, $held, $locale),
            GridNormalizer::ZONE_COUNTDOWN => $this->countdown($options, $held),
            GridNormalizer::ZONE_CONTACT_CARD => $this->contactCard($options, $held, $media($zone['mediaId'])),
            GridNormalizer::ZONE_SOCIAL_POST => $this->socialPost($options, $held, $locale, $media($zone['mediaId']), $media($options['socialAvatarId'])),
            GridNormalizer::ZONE_QR_CODE => $this->qrCode($zone, $options, $held, $media($options['qrLogoId'])),
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
