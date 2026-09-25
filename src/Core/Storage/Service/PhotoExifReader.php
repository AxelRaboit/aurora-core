<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use Throwable;

use function array_filter;
use function explode;
use function function_exists;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_rtrim;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function preg_match;
use function preg_replace;
use function round;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * The camera settings a photograph carries, in words a caption can print.
 *
 * Read before anything re-encodes the file: {@see ImageVariantGenerator}
 * rewrites a JPEG at quality 85 and its metadata goes with it. Only the
 * settings are kept - body, lens, aperture, speed, sensitivity, focal length
 * and the date - never the position, which would publish where a photographer
 * lives along with their picture.
 */
final readonly class PhotoExifReader
{
    public function __construct(
        private LocalWorkspace $workspace,
    ) {}

    /**
     * @return array<string, string> empty when the file says nothing, or is not a photograph
     */
    public function read(StorageAdapterInterface $adapter, string $key, string $mimeType): array
    {
        $mime = MimeTypeEnum::tryFrom($mimeType);

        if (!$mime?->isJpeg() || !function_exists('exif_read_data')) {
            return [];
        }

        try {
            $raw = $this->workspace->readable($adapter, $key, static fn (string $path): mixed => @exif_read_data($path, 'EXIF', true));
        } catch (Throwable) {
            return [];
        }

        return is_array($raw) ? self::describe($raw) : [];
    }

    /**
     * @param array<string, mixed> $raw what exif_read_data returns, sections included
     *
     * @return array<string, string>
     */
    public static function describe(array $raw): array
    {
        $ifd0 = is_array($raw['IFD0'] ?? null) ? $raw['IFD0'] : [];
        $exif = is_array($raw['EXIF'] ?? null) ? $raw['EXIF'] : [];

        $make = self::text($ifd0['Make'] ?? null);
        $model = self::text($ifd0['Model'] ?? null);
        // Most bodies repeat the maker in the model: « Canon Canon EOS R6 ».
        $camera = '' !== $make && '' !== $model && !str_starts_with(mb_strtolower($model), mb_strtolower(explode(' ', $make)[0]))
            ? $make.' '.$model
            : ('' !== $model ? $model : $make);

        $values = [
            'camera' => $camera,
            'lens' => self::text($exif['UndefinedTag:0xA434'] ?? $exif['LensModel'] ?? null),
            'aperture' => null === ($f = self::ratio($exif['FNumber'] ?? null)) ? '' : sprintf('f/%s', self::decimal($f)),
            'shutter' => self::shutter($exif['ExposureTime'] ?? null),
            'iso' => is_numeric($iso = is_array($exif['ISOSpeedRatings'] ?? null) ? ($exif['ISOSpeedRatings'][0] ?? null) : ($exif['ISOSpeedRatings'] ?? null)) ? sprintf('ISO %d', (int) $iso) : '',
            'focal' => null === ($focal = self::ratio($exif['FocalLength'] ?? null)) ? '' : sprintf('%s mm', self::decimal($focal)),
            'takenAt' => 1 === preg_match('/^(\d{4}):(\d{2}):(\d{2})/', self::text($exif['DateTimeOriginal'] ?? null), $m) ? sprintf('%s-%s-%s', $m[1], $m[2], $m[3]) : '',
        ];

        return array_filter($values, static fn (string $value): bool => '' !== $value);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(mb_trim((string) preg_replace('/[\x00-\x1F]+/', '', $value)), 0, 80) : '';
    }

    /** `28/10` as 2.8; EXIF writes most figures as fractions. */
    private static function ratio(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value) || !str_contains($value, '/')) {
            return null;
        }

        [$top, $bottom] = explode('/', $value, 2);

        return is_numeric($top) && is_numeric($bottom) && 0.0 !== (float) $bottom ? (float) $top / (float) $bottom : null;
    }

    /** 1/500 s under a second, 2 s above: how a photographer says it. */
    private static function shutter(mixed $value): string
    {
        $seconds = self::ratio($value);

        if (null === $seconds || $seconds <= 0) {
            return '';
        }

        return $seconds >= 1 ? sprintf('%s s', self::decimal($seconds)) : sprintf('1/%d s', (int) round(1 / $seconds));
    }

    private static function decimal(float $value): string
    {
        return mb_rtrim(mb_rtrim(sprintf('%.1f', $value), '0'), '.');
    }
}
