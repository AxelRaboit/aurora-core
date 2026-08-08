<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Service\ImageCropper;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Owns the on-disk handling for GED documents — slugifies the upload name,
 * picks a `var/uploads/ged/Y/m/<slug>-<uniqid>.<ext>` destination, moves
 * the uploaded bytes, returns the metadata the form needs to persist on
 * the `Document` entity.
 *
 * Kept as a thin standalone service (no entity coupling) so the controller's
 * `/upload` endpoint can call it without going through the manager. The
 * actual `Document` row creation happens on the form submit, with the
 * `filePath` carried in the DocumentInput DTO.
 */
final readonly class GedDocumentUploader
{
    public function __construct(
        private Filesystem $filesystem,
        private SluggerInterface $slugger,
        private PdfThumbnailGenerator $pdfThumbnailGenerator,
        private ImageCropper $imageCropper,
        #[Autowire(param: 'app.upload_dir')]
        private string $uploadDir,
    ) {}

    /**
     * @return array{filePath: string, fileName: string, originalName: string, mimeType: string, size: int, thumbnailPath: string|null, width: int|null, height: int|null}
     */
    public function upload(UploadedFile $file): array
    {
        $mimeType = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $clientName = $file->getClientOriginalName();

        $safeFilename = $this->slugger->slug(pathinfo($clientName, PATHINFO_FILENAME))->lower();
        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $dateSlug = new DateTimeImmutable()->format('Y/m');
        $newFilename = sprintf('%s-%s.%s', $safeFilename, uniqid(), $extension);
        $relativeDir = sprintf('%s/%s', StorageAreaEnum::Ged->value, $dateSlug);
        $relativePath = sprintf('%s/%s', $relativeDir, $newFilename);

        $this->filesystem->mkdir(Path::join($this->uploadDir, $relativeDir));
        $file->move(Path::join($this->uploadDir, $relativeDir), $newFilename);

        $thumbnailPath = null;
        if (MimeTypeEnum::Pdf->value === $mimeType) {
            $thumbDir = sprintf('%s/thumbnails/%s', StorageAreaEnum::Ged->value, $dateSlug);
            $thumbBasename = pathinfo($newFilename, PATHINFO_FILENAME);
            $thumbnailPath = $this->pdfThumbnailGenerator->generate($relativePath, $thumbDir, $thumbBasename);
        }

        [$width, $height] = $this->readImageDimensions(Path::join($this->uploadDir, $relativePath), $mimeType);

        return [
            'filePath' => $relativePath,
            'fileName' => $newFilename,
            'originalName' => $clientName,
            'mimeType' => $mimeType,
            'size' => $size,
            'thumbnailPath' => $thumbnailPath,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Crops a source image to a brand-new file under `ged/Y/m/…` (the source
     * is left untouched on disk, so the previous version's bytes survive) and
     * returns the metadata the manager persists on the document. Returns null
     * when the source is not a croppable raster image.
     *
     * @return array{filePath: string, fileName: string, size: int, width: int, height: int}|null
     */
    public function cropToNewFile(
        string $sourceRelativePath,
        string $mimeType,
        string $baseName,
        int $x,
        int $y,
        int $width,
        int $height,
    ): ?array {
        $extension = MimeTypeEnum::tryFrom($mimeType)?->extension()
            ?? pathinfo($sourceRelativePath, PATHINFO_EXTENSION);
        $safeFilename = $this->slugger->slug(pathinfo($baseName, PATHINFO_FILENAME))->lower();
        $dateSlug = new DateTimeImmutable()->format('Y/m');
        $newFilename = sprintf('%s-%s.%s', $safeFilename, uniqid(), $extension);
        $relativePath = sprintf('%s/%s/%s', StorageAreaEnum::Ged->value, $dateSlug, $newFilename);

        $dimensions = $this->imageCropper->crop(
            Path::join($this->uploadDir, $sourceRelativePath),
            Path::join($this->uploadDir, $relativePath),
            $mimeType,
            $x,
            $y,
            $width,
            $height,
        );

        if (null === $dimensions) {
            return null;
        }

        return [
            'filePath' => $relativePath,
            'fileName' => $newFilename,
            'size' => @filesize(Path::join($this->uploadDir, $relativePath)) ?: 0,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ];
    }

    /**
     * Removes a file owned by the GED storage area (relative to var/uploads/).
     * Silently no-ops on a missing file. Used when pruning old versions.
     */
    public function deleteFile(string $relativePath): void
    {
        if ('' === $relativePath) {
            return;
        }

        $this->filesystem->remove(Path::join($this->uploadDir, $relativePath));
    }

    /**
     * Reads pixel dimensions for raster images. Returns [null, null] for
     * non-images or unreadable files — never throws.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function readImageDimensions(string $absolutePath, string $mimeType): array
    {
        if (!str_starts_with($mimeType, 'image/')) {
            return [null, null];
        }

        $info = @getimagesize($absolutePath);
        if (false === $info) {
            return [null, null];
        }

        return [$info[0], $info[1]];
    }
}
