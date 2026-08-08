<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

use function sprintf;

/**
 * Resolves the absolute filesystem path of any relative `var/uploads/`
 * path (GED Document, Welding PdfDocument, profile photos, …). Centralises
 * the `uploadDir.'/'.relativePath` concatenation so callers (variant
 * generators, exporters, OCR pipeline…) don't reinvent it inline.
 *
 * Throws when the file is missing on disk to surface upload/cleanup bugs
 * loudly rather than silently producing a broken downstream operation.
 *
 * Renamed + relocated out of `Module/Media/Library/Service/` during the
 * Phase 5 Media → GED merge — only the entity-agnostic
 * `resolveByRelativePath()` method was kept (the Media-specific
 * `resolveAbsolutePath(MediaInterface)` was dropped along with Media).
 */
final readonly class UploadPathResolver
{
    public function __construct(
        #[Autowire(param: 'app.upload_dir')]
        private string $uploadDir,
    ) {}

    public function resolveByRelativePath(string $relativePath): string
    {
        $absolutePath = Path::join($this->uploadDir, $relativePath);

        if (!is_file($absolutePath)) {
            throw new RuntimeException(sprintf('File missing on disk: %s', $absolutePath));
        }

        return $absolutePath;
    }
}
