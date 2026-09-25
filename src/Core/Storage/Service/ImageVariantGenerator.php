<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use GdImage;
use Symfony\Component\Filesystem\Path;

final readonly class ImageVariantGenerator
{
    public const array VARIANT_SIZES = [
        'thumbnail' => 256,
        'medium' => 800,
        'large' => 1920,
        // For a picture that spans a high-density screen - a full-width
        // banner on a 1440px Retina display draws 2880 device pixels, and
        // `large` stretched that far goes visibly soft. Only made when the
        // source actually has the pixels: an upscale would cost the storage
        // and add nothing.
        'xlarge' => 3840,
    ];

    /**
     * The variant that always exists, whatever the source's size, so the
     * public path never falls back to the raw file.
     */
    private const string ALWAYS = 'large';

    public function __construct(
        private LocalWorkspace $workspace,
    ) {}

    /**
     * Generate all variants for a given source image.
     * Variants are generated as WebP when supported (better compression, universal browser support).
     * GIFs keep their original format to preserve animation.
     *
     * @return array<string, string> variant name → key of the stored variant
     */
    public function generate(StorageAdapterInterface $adapter, string $sourceKey, string $mimeType): array
    {
        $mime = MimeTypeEnum::tryFrom($mimeType);
        if (!$mime?->isRasterImage()) {
            return [];
        }

        if (!$adapter->exists($sourceKey)) {
            return [];
        }

        $work = fn (string $sourceAbsolute): array => $this->generateFrom($adapter, $sourceKey, $sourceAbsolute, $mime);

        // Only JPEG sources are rewritten in place (re-encoded at quality 85 to
        // strip metadata), so only they need storing back afterwards. Asking
        // for a writable copy of a PNG would mean uploading an untouched file
        // on a backend that bills per write.
        return $mime->isJpeg()
            ? $this->workspace->writable($adapter, $sourceKey, $work)
            : $this->workspace->readable($adapter, $sourceKey, $work);
    }

    /**
     * @return array<string, string>
     */
    private function generateFrom(StorageAdapterInterface $adapter, string $sourceKey, string $sourceAbsolute, MimeTypeEnum $mime): array
    {
        $source = $this->load($sourceAbsolute, $mime);
        if (!$source instanceof GdImage) {
            return [];
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $extension = pathinfo($sourceKey, PATHINFO_EXTENSION);
        $baseName = pathinfo($sourceKey, PATHINFO_FILENAME);

        $useWebP = function_exists('imagewebp') && !$mime->supportsAnimation();
        $variantMime = $useWebP ? MimeTypeEnum::Webp : $mime;
        $variantExtension = $useWebP ? 'webp' : $extension;

        // Re-encode JPEG at quality 85 to strip metadata and reduce file size.
        if ($mime->isJpeg()) {
            imagejpeg($source, $sourceAbsolute, 85);
        }

        $generated = [];
        foreach (self::VARIANT_SIZES as $variantName => $maxSide) {
            // Skip downscale when source is already smaller - EXCEPT for
            // `large`: we always want a re-encoded "large" variant so the
            // public download path (web) never falls back to the raw source,
            // which would leak EXIF (geo/camera) on PNG/WebP originals.
            if (self::ALWAYS !== $variantName && $this->fitsBelow($variantName, $sourceWidth, $sourceHeight)) {
                continue;
            }

            $shouldDownscale = $sourceWidth > $maxSide || $sourceHeight > $maxSide;
            [$targetWidth, $targetHeight] = $shouldDownscale
                ? $this->fitDimensions($sourceWidth, $sourceHeight, $maxSide)
                : [$sourceWidth, $sourceHeight];

            $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
            $this->preserveTransparency($targetImage, $variantMime);

            imagecopyresampled($targetImage, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

            $variantKey = Path::join(dirname($sourceKey), 'variants', $variantName, sprintf('%s.%s', $baseName, $variantExtension));

            $this->workspace->target($adapter, $variantKey, function (string $output) use ($targetImage, $variantMime): void {
                $this->save($targetImage, $output, $variantMime);
            });
            imagedestroy($targetImage);

            $generated[$variantName] = $variantKey;
        }

        imagedestroy($source);

        return $generated;
    }

    /**
     * Whether the source is too small for this variant to add anything.
     *
     * A shrinking variant is pointless once the source fits inside it. The
     * extra-large one is different: it exists to carry more pixels than
     * `large`, so it is worth making as soon as the source outgrows `large` -
     * a 3840px source fits inside 3840 exactly, and skipping it there would
     * leave the one picture that needs it without it.
     */
    private function fitsBelow(string $variantName, int $width, int $height): bool
    {
        $threshold = 'xlarge' === $variantName ? self::VARIANT_SIZES[self::ALWAYS] : self::VARIANT_SIZES[$variantName];

        return $width <= $threshold && $height <= $threshold;
    }

    /**
     * @param array<string, string> $variants
     */
    public function deleteVariants(StorageAdapterInterface $adapter, array $variants): void
    {
        // One call rather than one per variant: a backend that bills per
        // request charges for each, and every deleted document has three.
        $adapter->deleteMany(array_values($variants));
    }

    /** @return array{0: int, 1: int} */
    private function fitDimensions(int $sourceWidth, int $sourceHeight, int $maxSide): array
    {
        if ($sourceWidth >= $sourceHeight) {
            $targetWidth = $maxSide;
            $targetHeight = max(1, (int) round($sourceHeight * ($maxSide / $sourceWidth)));
        } else {
            $targetHeight = $maxSide;
            $targetWidth = max(1, (int) round($sourceWidth * ($maxSide / $sourceHeight)));
        }

        return [$targetWidth, $targetHeight];
    }

    private function load(string $path, MimeTypeEnum $mime): ?GdImage
    {
        $resource = match (true) {
            $mime->isJpeg() => @imagecreatefromjpeg($path),
            MimeTypeEnum::Png === $mime => @imagecreatefrompng($path),
            MimeTypeEnum::Gif === $mime => @imagecreatefromgif($path),
            MimeTypeEnum::Webp === $mime => @imagecreatefromwebp($path),
            default => false,
        };

        return $resource instanceof GdImage ? $resource : null;
    }

    private function save(GdImage $image, string $path, MimeTypeEnum $mime): void
    {
        match (true) {
            $mime->isJpeg() => imagejpeg($image, $path, 85),
            MimeTypeEnum::Png === $mime => imagepng($image, $path, 6),
            MimeTypeEnum::Gif === $mime => imagegif($image, $path),
            MimeTypeEnum::Webp === $mime => imagewebp($image, $path, 85),
            default => null,
        };
    }

    private function preserveTransparency(GdImage $image, MimeTypeEnum $mime): void
    {
        if (!$mime->supportsAlpha()) {
            return;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
    }
}
