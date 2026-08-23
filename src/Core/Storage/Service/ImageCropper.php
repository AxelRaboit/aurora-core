<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Support\Num;
use GdImage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Crops a raster image to a pixel rectangle and writes the result, preserving
 * the source format and its encoding quality.
 *
 * Pure pixel work - no entity/persistence concerns. Shared by every feature
 * that lets a user re-frame an image (Media library in-place, GED documents
 * to a fresh file, …) so the GD load/crop/save logic lives in exactly one
 * place. The crop rectangle is clamped to the image bounds, so callers can
 * forward raw client coordinates without re-validating.
 */
final readonly class ImageCropper
{
    public function __construct(
        private Filesystem $filesystem,
    ) {}

    /**
     * Crops the source image and writes it to the destination path (which may
     * be the same file for an in-place crop). Returns the resulting
     * [width, height], or null when the source is not a supported raster image
     * or cannot be read.
     *
     * @return array{0: int, 1: int}|null
     */
    public function crop(
        string $sourceAbsolutePath,
        string $destinationAbsolutePath,
        string $mimeType,
        int $x,
        int $y,
        int $width,
        int $height,
    ): ?array {
        $mime = MimeTypeEnum::tryFrom($mimeType);
        if (!$mime?->isRasterImage() || !is_file($sourceAbsolutePath)) {
            return null;
        }

        $source = $this->load($sourceAbsolutePath, $mime);
        if (!$source instanceof GdImage) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        // Clamp to image bounds so out-of-range client coordinates never crash GD.
        $x = Num::clamp($x, 0, $sourceWidth - 1);
        $y = Num::clamp($y, 0, $sourceHeight - 1);
        $width = Num::clamp($width, 1, $sourceWidth - $x);
        $height = Num::clamp($height, 1, $sourceHeight - $y);

        $cropped = imagecreatetruecolor($width, $height);
        $this->preserveTransparency($cropped, $mime);
        imagecopy($cropped, $source, 0, 0, $x, $y, $width, $height);
        imagedestroy($source);

        $this->filesystem->mkdir(Path::getDirectory($destinationAbsolutePath));
        $this->save($cropped, $destinationAbsolutePath, $mime);
        imagedestroy($cropped);

        [$newWidth, $newHeight] = @getimagesize($destinationAbsolutePath) ?: [$width, $height];

        return [$newWidth, $newHeight];
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
