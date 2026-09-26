<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

use function array_fill_keys;
use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function filter_var;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function min;
use function preg_match;
use function sort;
use function str_starts_with;
use function usort;

use const FILTER_VALIDATE_EMAIL;

/**
 * The settings that belong to one kind of zone, in one object.
 *
 * Every zone already carries every top-level key whatever its type, so that
 * switching a zone from one type to another and back loses nothing. A
 * carousel, a device frame, an opening-hours table and a social post each
 * need settings of their own, and forty more top-level keys would drown the
 * ones every zone shares. They live here instead, under `options` - still
 * present on every zone, still shared across languages, still normalised on
 * the way in so the front never guards a read.
 *
 * Words are not here: what a zone says lives on the translation, in the
 * `label` and `caption` it already has.
 */
final class GridZoneOptions
{
    public const array GALLERY_LAYOUTS = ['grid', 'carousel'];

    /** What a media zone's picture is drawn inside. */
    public const array FRAMES = ['none', 'laptop', 'phone', 'browser'];

    public const array CODE_STYLES = ['plain', 'terminal', 'diff'];

    public const array LIST_LAYOUTS = ['cards', 'index'];

    public const array GITHUB_MODES = ['activity', 'repos', 'releases'];

    public const int MAX_GITHUB_REPOS = 6;

    public const array AVAILABILITIES = ['available', 'soon', 'busy'];

    /** Monday first, the way a week is printed on a shop door in Europe. */
    public const array WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** A morning, an afternoon and an evening service at most. */
    public const int MAX_RANGES_PER_DAY = 3;

    public const int MAX_CLOSED_DATES = 30;

    public const string DEFAULT_TIMEZONE = 'Europe/Paris';

    public const array SOCIAL_NETWORKS = ['instagram', 'linkedin', 'facebook', 'x'];

    public const array CHART_TYPES = ['bar', 'line', 'donut', 'growth'];

    /** When a poll shows its results: once the reader has voted, or from the start. */
    public const array POLL_RESULTS = ['after', 'always'];

    public const array SLOT_DURATIONS = [15, 30, 45, 60, 90];

    public const array BOOKING_WINDOWS = [7, 14, 21, 30, 45];

    /**
     * Every key, with the value a zone arrives with.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'galleryLayout' => self::GALLERY_LAYOUTS[0],
            'frame' => self::FRAMES[0],
            'parallax' => false,
            'codeStyle' => self::CODE_STYLES[0],
            'listLayout' => self::LIST_LAYOUTS[0],
            'githubMode' => self::GITHUB_MODES[0],
            'githubRepos' => [],
            'availability' => self::AVAILABILITIES[0],
            'availableFrom' => null,
            'hours' => array_fill_keys(self::WEEKDAYS, []),
            'closedDates' => [],
            'timezone' => self::DEFAULT_TIMEZONE,
            'countdownAt' => null,
            'contactName' => '',
            'contactPhone' => '',
            'contactEmail' => '',
            'contactWebsite' => null,
            'socialNetwork' => self::SOCIAL_NETWORKS[0],
            'socialName' => '',
            'socialHandle' => '',
            'socialAvatarId' => null,
            'socialLikes' => 0,
            'socialComments' => 0,
            'socialShares' => 0,
            'socialDate' => null,
            'qrLogoId' => null,
            'qrDownload' => true,
            'chartType' => self::CHART_TYPES[0],
            'chartUnit' => '',
            'showExif' => false,
            'backgroundVideo' => false,
            'calendarMonth' => null,
            'feedGithub' => false,
            'pollResults' => self::POLL_RESULTS[0],
            'quoteCurrency' => '€',
            'slotDuration' => self::SLOT_DURATIONS[0],
            'bookingWindowDays' => self::BOOKING_WINDOWS[0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalize(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        return [
            'galleryLayout' => self::oneOf($data['galleryLayout'] ?? null, self::GALLERY_LAYOUTS),
            'frame' => self::oneOf($data['frame'] ?? null, self::FRAMES),
            // A band that scrolls slower than the page. Only a full-bleed
            // picture has somewhere to move; the template reads both.
            'parallax' => true === ($data['parallax'] ?? false),
            'codeStyle' => self::oneOf($data['codeStyle'] ?? null, self::CODE_STYLES),
            'listLayout' => self::oneOf($data['listLayout'] ?? null, self::LIST_LAYOUTS),
            'githubMode' => self::oneOf($data['githubMode'] ?? null, self::GITHUB_MODES),
            'githubRepos' => self::repos($data['githubRepos'] ?? null),
            'availability' => self::oneOf($data['availability'] ?? null, self::AVAILABILITIES),
            'availableFrom' => self::day($data['availableFrom'] ?? null),
            'hours' => self::hours($data['hours'] ?? null),
            'closedDates' => self::days($data['closedDates'] ?? null, self::MAX_CLOSED_DATES),
            'timezone' => self::timezone($data['timezone'] ?? null),
            'countdownAt' => self::localDateTime($data['countdownAt'] ?? null),
            'contactName' => self::line($data['contactName'] ?? null, 120),
            // Digits, spaces, dots, dashes, brackets and one leading plus:
            // what a number looks like written down, and nothing that could
            // leave the `tel:` it becomes.
            'contactPhone' => 1 === preg_match('/^\+?[0-9 .()\-]{4,30}$/', self::line($data['contactPhone'] ?? null, 30))
                ? self::line($data['contactPhone'], 30)
                : '',
            'contactEmail' => false !== filter_var(self::line($data['contactEmail'] ?? null, 190), FILTER_VALIDATE_EMAIL)
                ? self::line($data['contactEmail'], 190)
                : '',
            'contactWebsite' => self::httpUrl($data['contactWebsite'] ?? null),
            'socialNetwork' => self::oneOf($data['socialNetwork'] ?? null, self::SOCIAL_NETWORKS),
            'socialName' => self::line($data['socialName'] ?? null, 80),
            // A handle is shown with its @, so the @ typed by the author is
            // dropped rather than doubled.
            'socialHandle' => self::handle($data['socialHandle'] ?? null),
            'socialAvatarId' => self::id($data['socialAvatarId'] ?? null),
            'socialLikes' => self::count($data['socialLikes'] ?? null),
            'socialComments' => self::count($data['socialComments'] ?? null),
            'socialShares' => self::count($data['socialShares'] ?? null),
            'socialDate' => self::day($data['socialDate'] ?? null),
            // The picture in the middle of a QR code, from the library.
            'qrLogoId' => self::id($data['qrLogoId'] ?? null),
            // On unless switched off: a QR code on a page is most often there
            // to be printed somewhere else too.
            'qrDownload' => false !== ($data['qrDownload'] ?? true),
            'chartType' => self::oneOf($data['chartType'] ?? null, self::CHART_TYPES),
            // What follows every figure: « % », « € », « k ». Short, because
            // it is printed beside each value.
            'chartUnit' => self::line($data['chartUnit'] ?? null, 12),
            // The camera line under a photograph, read from its file.
            'showExif' => true === ($data['showExif'] ?? false),
            // A film playing without sound behind a title, instead of a player.
            'backgroundVideo' => true === ($data['backgroundVideo'] ?? false),
            'calendarMonth' => self::month($data['calendarMonth'] ?? null),
            'feedGithub' => true === ($data['feedGithub'] ?? false),
            'pollResults' => self::oneOf($data['pollResults'] ?? null, self::POLL_RESULTS),
            // Printed straight after the number, so an ordinary currency sign
            // needs no space of its own; a longer word can carry its own.
            'quoteCurrency' => self::line($data['quoteCurrency'] ?? null, 6) ?: '€',
            'slotDuration' => self::intOneOf($data['slotDuration'] ?? null, self::SLOT_DURATIONS),
            'bookingWindowDays' => self::intOneOf($data['bookingWindowDays'] ?? null, self::BOOKING_WINDOWS),
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $allowed[0];
    }

    /** @param list<int> $allowed */
    private static function intOneOf(mixed $value, array $allowed): int
    {
        $int = is_numeric($value) ? (int) $value : null;

        return null !== $int && in_array($int, $allowed, true) ? $int : $allowed[0];
    }

    private static function line(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(mb_trim($value), 0, $max) : '';
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(999_999_999, (int) $value)) : 0;
    }

    private static function handle(mixed $value): string
    {
        $handle = self::line($value, 60);

        if ('' !== $handle && '@' === $handle[0]) {
            $handle = mb_substr($handle, 1);
        }

        return 1 === preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $handle) ? $handle : '';
    }

    private static function httpUrl(mixed $value): ?string
    {
        $url = self::line($value, 500);

        return 1 === preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : null;
    }

    /** A calendar day, as the date field writes it. */
    private static function day(mixed $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** `2026-10`, as the month picker writes it. */
    private static function month(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }

    /**
     * A moment on the wall clock of the zone's timezone, without the zone:
     * `2026-11-14T18:30`, as the datetime field writes it.
     */
    private static function localDateTime(mixed $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/', $value, $match)) {
            return null;
        }

        if (null === self::day($match[1]) || (int) $match[2] > 23 || (int) $match[3] > 59) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function days(mixed $value, int $max): array
    {
        $days = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            $day = self::day($entry);

            if (null !== $day && !in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        sort($days);

        return array_slice($days, 0, $max);
    }

    private static function timezone(mixed $value): string
    {
        if (!is_string($value) || '' === $value) {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            return new DateTimeZone($value)->getName();
        } catch (Throwable) {
            return self::DEFAULT_TIMEZONE;
        }
    }

    /**
     * Opening ranges per weekday, each `["09:00", "12:30"]`, in order.
     *
     * A range that closes before it opens is dropped rather than swapped: a
     * night service that runs past midnight is two ranges on two days, and
     * guessing which one the author meant would publish the wrong hours.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private static function hours(mixed $value): array
    {
        $data = is_array($value) ? $value : [];
        $week = [];

        foreach (self::WEEKDAYS as $weekday) {
            $ranges = [];

            foreach (is_array($data[$weekday] ?? null) ? $data[$weekday] : [] as $range) {
                if (!is_array($range)) {
                    continue;
                }

                if (2 !== count($range)) {
                    continue;
                }

                $range = array_values($range);
                $open = self::clock($range[0]);
                $close = self::clock($range[1]);

                if (null !== $open && null !== $close && $open < $close) {
                    $ranges[] = [$open, $close];
                }
            }

            usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $week[$weekday] = array_slice($ranges, 0, self::MAX_RANGES_PER_DAY);
        }

        return $week;
    }

    private static function clock(mixed $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^(\d{2}):(\d{2})$/', $value, $match)) {
            return null;
        }

        // 24:00 is allowed as a closing time: "open until midnight" is how
        // a late bar writes it, and 23:59 would print as a lie.
        if ('24:00' === $value) {
            return $value;
        }

        return (int) $match[1] <= 23 && (int) $match[2] <= 59 ? $value : null;
    }

    /**
     * `owner/repository`, as GitHub prints it.
     *
     * @return list<string>
     */
    private static function repos(mixed $value): array
    {
        $repos = [];
        $seen = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $repo = mb_trim($entry);

            if (1 !== preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}/[A-Za-z0-9._-]{1,100}$#', $repo)) {
                continue;
            }

            $key = mb_strtolower($repo);

            // A name that starts with a dot is a path trick, not a repository.
            if (!array_key_exists($key, $seen) && !str_starts_with(explode('/', $repo)[1], '.')) {
                $seen[$key] = true;
                $repos[] = $repo;
            }
        }

        return array_slice($repos, 0, self::MAX_GITHUB_REPOS);
    }
}
