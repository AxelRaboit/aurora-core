<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Service\ImageCropper;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Core\Storage\Service\VideoCapture;
use Aurora\Core\Storage\Service\VideoPosterGenerator;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\StoredFileName;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Owns the on-disk handling for GED documents - picks a
 * `var/uploads/ged/Y/m/<32 random hex>.<ext>` destination, moves the uploaded
 * bytes, returns the metadata the form needs to persist on the `Document`
 * entity.
 *
 * The stored name carries nothing of the original one. A published document is
 * served to anybody who knows its address, so that address is its only lock,
 * and a name built from `slug(original) + uniqid()` - which is what this did
 * until 2026-09-16 - was derivable from a guessable filename and the moment of
 * the upload. {@see StoredFileName} states the rule;
 * the readable name lives in the document's `originalName` column, where a
 * download can still use it.
 *
 * Kept as a thin standalone service (no entity coupling) so the controller's
 * `/upload` endpoint can call it without going through the manager. The
 * actual `Document` row creation happens on the form submit, with the
 * `filePath` carried in the DocumentInput DTO.
 */
final readonly class GedDocumentUploader
{
    public function __construct(
        private PdfThumbnailGenerator $pdfThumbnailGenerator,
        private VideoPosterGenerator $videoPosterGenerator,
        private ImageCropper $imageCropper,
        private StorageManager $storageManager,
        private LocalWorkspace $workspace,
    ) {}

    /**
     * @param VideoCapture|null $capture frame the browser drew from a film it
     *                                   was about to upload, when there was a
     *                                   browser to draw it
     *
     * @return array{filePath: string, fileName: string, originalName: string, mimeType: string, size: int, thumbnailPath: string|null, width: int|null, height: int|null}
     */
    public function upload(UploadedFile $file, ?VideoCapture $capture = null): array
    {
        $mimeType = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $clientName = $file->getClientOriginalName();

        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $dateSlug = new DateTimeImmutable()->format('Y/m');
        // Random, and carrying nothing of what the file was called: for a
        // published document the address is the only lock, so the name it is
        // stored under must not be derivable from its original name and the
        // moment it arrived. See {@see StoredFileName}. `originalName` keeps
        // the human-readable one, which is what a download needs.
        $newFilename = StoredFileName::withExtension($extension);
        $relativeDir = sprintf('%s/%s', StorageAreaEnum::Ged->value, $dateSlug);
        $relativePath = sprintf('%s/%s', $relativeDir, $newFilename);

        $adapter = $this->storageManager->active();

        // PHP already put the upload somewhere on this machine; handing that
        // path over rather than moving it first means one copy instead of two,
        // and the temporary is swept at the end of the request either way.
        $adapter->writeFromLocalFile($relativePath, $file->getPathname());

        $thumbDir = sprintf('%s/thumbnails/%s', StorageAreaEnum::Ged->value, $dateSlug);
        $thumbBasename = pathinfo($newFilename, PATHINFO_FILENAME);

        $thumbnailPath = null;
        [$width, $height] = $this->readImageDimensions($adapter, $relativePath, $mimeType);

        if (MimeTypeEnum::Pdf->value === $mimeType) {
            $thumbnailPath = $this->pdfThumbnailGenerator->generate($adapter, $relativePath, $thumbDir, $thumbBasename);
        }

        if (MimeTypeEnum::tryFrom($mimeType)?->isVideo() ?? false) {
            // The browser's frame first: it cost nothing to make and needs
            // nothing installed here. `fromSource` is the path for uploads no
            // browser handled - an API call, a fixture, a console import.
            $thumbnailPath = $capture instanceof VideoCapture
                ? $this->videoPosterGenerator->fromCapture($adapter, $capture->poster, $thumbDir, $thumbBasename)
                : $this->videoPosterGenerator->fromSource($adapter, $relativePath, $thumbDir, $thumbBasename);

            [$width, $height] = $this->readVideoDimensions($adapter, $capture, $thumbnailPath);
        }

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
        $dateSlug = new DateTimeImmutable()->format('Y/m');
        // Random like the upload above, and for the same reason: a crop is a
        // new published file at a new address, so a name derived from the
        // source's would hand out the source's address too.
        $newFilename = StoredFileName::withExtension($extension);
        $relativePath = sprintf('%s/%s/%s', StorageAreaEnum::Ged->value, $dateSlug, $newFilename);

        $adapter = $this->storageManager->active();

        $dimensions = $this->workspace->readable(
            $adapter,
            $sourceRelativePath,
            fn (string $source): ?array => $this->workspace->target(
                $adapter,
                $relativePath,
                fn (string $destination): ?array => $this->imageCropper->crop(
                    $source,
                    $destination,
                    $mimeType,
                    $x,
                    $y,
                    $width,
                    $height,
                ),
            ),
        );

        if (null === $dimensions) {
            return null;
        }

        // The crop just stored these bytes, so the size comes from the object
        // it wrote rather than from a second look at the disk.
        $stored = $adapter->stat($relativePath);

        return [
            'filePath' => $relativePath,
            'fileName' => $newFilename,
            'size' => $stored instanceof StoredObject ? $stored->size : 0,
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

        $this->storageManager->active()->delete($relativePath);
    }

    /**
     * Reads pixel dimensions for raster images. Returns [null, null] for
     * non-images or unreadable files - never throws.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function readImageDimensions(StorageAdapterInterface $adapter, string $key, string $mimeType): array
    {
        if (!str_starts_with($mimeType, 'image/')) {
            return [null, null];
        }

        return $this->workspace->readable($adapter, $key, static function (string $path): array {
            $info = @getimagesize($path);

            return false === $info ? [null, null] : [$info[0], $info[1]];
        });
    }

    /**
     * Pixel dimensions of a film, without opening the film.
     *
     * The player that uploaded it already knew them, so its answer wins. When
     * there was no player, the poster stands in: `ffmpeg` writes the frame at
     * the source's own resolution, so reading the still reads the film. With
     * neither, the document keeps the nulls it would have had anyway - the
     * ratio is then the browser's default, which is the state this whole
     * feature exists to get out of.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function readVideoDimensions(
        StorageAdapterInterface $adapter,
        ?VideoCapture $capture,
        ?string $posterKey,
    ): array {
        if ($capture instanceof VideoCapture && $capture->hasDimensions()) {
            return [$capture->videoWidth, $capture->videoHeight];
        }

        if (null === $posterKey || $capture instanceof VideoCapture) {
            return [null, null];
        }

        return $this->readImageDimensions($adapter, $posterKey, MimeTypeEnum::Jpeg->value);
    }
}
