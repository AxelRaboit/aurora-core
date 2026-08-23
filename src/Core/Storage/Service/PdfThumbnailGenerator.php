<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire(param: 'app.upload_dir')]
        private string $uploadDir,
        private Filesystem $filesystem = new Filesystem(),
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Renders page 1 of `$sourceRelativePath` to a JPEG thumb under
     * `$thumbRelativeDir` and returns its relative path, or `null` on
     * any failure.
     *
     * @param string $sourceRelativePath path under var/uploads/ to the PDF
     * @param string $thumbRelativeDir   directory under var/uploads/ for the output
     * @param string $basename           output filename without extension
     */
    public function generate(string $sourceRelativePath, string $thumbRelativeDir, string $basename): ?string
    {
        $sourceAbsolute = Path::join($this->uploadDir, $sourceRelativePath);
        if (!$this->filesystem->exists($sourceAbsolute)) {
            $this->logger->warning('PdfThumbnailGenerator: source missing', ['path' => $sourceAbsolute]);

            return null;
        }

        $thumbDirAbsolute = Path::join($this->uploadDir, $thumbRelativeDir);
        $this->filesystem->mkdir($thumbDirAbsolute);

        $thumbFilename = sprintf('%s.%s', $basename, MimeTypeEnum::Jpeg->extension());
        $thumbAbsolute = Path::join($thumbDirAbsolute, $thumbFilename);

        if ($this->tryPdftoppm($sourceAbsolute, $thumbAbsolute) || $this->tryGhostscript($sourceAbsolute, $thumbAbsolute)) {
            return Path::join($thumbRelativeDir, $thumbFilename);
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
