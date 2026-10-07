<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Generates a JPEG thumbnail of the first page of a PDF.
 *
 * Strategy order (first available wins):
 *   1. `pdftoppm` (poppler-utils) - best quality, fastest, the industry default.
 *   2. `gs` (Ghostscript)        - works everywhere LaTeX/printing tooling lives.
 *
 * If neither binary is found, `generate()` returns `null` and logs a warning
 * rather than throwing - the GED list keeps working with the icon fallback.
 *
 * Output is a JPEG (~30-50 KB at scale-to 400 px) suitable for in-list
 * previews. Callers receive the relative path under `var/uploads/` to be
 * stored alongside the source document.
 */
final readonly class PdfThumbnailGenerator
{
    public function __construct(
        private LocalWorkspace $workspace,
        private Filesystem $filesystem = new Filesystem(),
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Renders page 1 of `$sourceKey` to a JPEG thumb under `$thumbDirectoryKey` and
     * returns the key it was stored at, or `null` on any failure.
     *
     * Both binaries take filenames and neither will take a stream, so the work
     * runs against paths {@see LocalWorkspace} provides - the stored file
     * itself on a local disk, a temporary elsewhere. When neither backend
     * produces anything the workspace stores nothing, so a failed render
     * leaves no empty object behind.
     *
     * @param string $sourceKey         key of the PDF to render
     * @param string $thumbDirectoryKey key prefix the output is stored under
     * @param string $basename          output filename without extension
     */
    public function generate(StorageAdapterInterface $adapter, string $sourceKey, string $thumbDirectoryKey, string $basename): ?string
    {
        if (!$adapter->exists($sourceKey)) {
            $this->logger->warning('PdfThumbnailGenerator: source missing', ['key' => $sourceKey]);

            return null;
        }

        $thumbKey = Path::join($thumbDirectoryKey, sprintf('%s.%s', $basename, MimeTypeEnum::Jpeg->extension()));

        $rendered = $this->workspace->readable(
            $adapter,
            $sourceKey,
            fn (string $sourceAbsolute): bool => $this->workspace->target(
                $adapter,
                $thumbKey,
                fn (string $output): bool => $this->tryPdftoppm($sourceAbsolute, $output)
                    || $this->tryGhostscript($sourceAbsolute, $output),
            ),
        );

        if ($rendered) {
            return $thumbKey;
        }

        $this->logger->warning('PdfThumbnailGenerator: no working backend (install poppler-utils or ghostscript)');

        return null;
    }

    private function tryPdftoppm(string $source, string $output): bool
    {
        $binary = $this->executableFinder->find('pdftoppm');
        if (null === $binary) {
            return false;
        }

        // `-singlefile` writes to "<prefix>.<ext>" so we strip the extension.
        $prefix = preg_replace('/\.'.MimeTypeEnum::Jpeg->extension().'$/', '', $output) ?? $output;

        $process = new Process([
            $binary,
            '-jpeg',
            '-r', '100',
            '-singlefile',
            '-scale-to', '400',
            $source,
            $prefix,
        ]);
        $process->setTimeout(30.0);

        try {
            $process->run();

            return $process->isSuccessful() && $this->filesystem->exists($output);
        } catch (Throwable $throwable) {
            $this->logger->warning('PdfThumbnailGenerator: pdftoppm failed', ['error' => $throwable->getMessage()]);

            return false;
        }
    }

    private function tryGhostscript(string $source, string $output): bool
    {
        $binary = $this->executableFinder->find('gs');
        if (null === $binary) {
            return false;
        }

        $process = new Process([
            $binary,
            '-dNOPAUSE',
            '-dBATCH',
            '-dQUIET',
            '-sDEVICE=jpeg',
            '-dJPEGQ=85',
            '-r100',
            '-dFirstPage=1',
            '-dLastPage=1',
            '-dUseCropBox',
            '-sOutputFile='.$output,
            $source,
        ]);
        $process->setTimeout(30.0);

        try {
            $process->run();

            return $process->isSuccessful() && $this->filesystem->exists($output);
        } catch (Throwable $throwable) {
            $this->logger->warning('PdfThumbnailGenerator: ghostscript failed', ['error' => $throwable->getMessage()]);

            return false;
        }
    }
}
