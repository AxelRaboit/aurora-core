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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Produces the still a `<video>` shows before anything is downloaded.
 *
 * Without one, a `preload="none"` player is a black rectangle sized by the
 * browser's default 300x150 intrinsic ratio, with `0:00` on the clock. The
 * theme has always known how to render a poster; nothing produced one.
 *
 * Two ways in, in this order of preference:
 *
 *   1. {@see fromCapture()} - the browser that uploaded the film drew a frame
 *      itself and sent it along. It already carries the codecs, so this needs
 *      nothing installed on the server, and it can only fail on a video no
 *      visitor could have played either.
 *   2. {@see fromSource()} - `ffmpeg`, for the uploads no browser handled: the
 *      API, fixtures, a console import, or {@see GenerateThumbnailsCommand}
 *      catching up on films stored before this existed.
 *
 * When neither applies the document simply has no poster, exactly as before.
 * `ffmpeg` is never required: installing it pulls close to two hundred
 * packages, GPU drivers and a speech recognition engine included, onto every
 * server an Aurora runs on. That is a lot to ask for one frame, which is why
 * the browser does the work whenever there is a browser.
 *
 * @see PdfThumbnailGenerator the same contract for a PDF's first page
 */
final readonly class VideoPosterGenerator
{
    /**
     * Where the frame is taken from. Far enough in to clear a fade from black
     * or a title card, early enough that a short clip still has footage there.
     */
    private const string SEEK_SECONDS = '1';

    public function __construct(
        private LocalWorkspace $workspace,
        private Filesystem $filesystem = new Filesystem(),
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Stores a poster the client drew, and returns the key it landed on.
     *
     * The bytes are not trusted because the field says `poster`: an upload
     * field is whatever was posted to it. The file has to decode as a raster
     * image, and its own type decides the extension, so a JPEG never ends up
     * served as `.webp` because the browser fell back silently.
     *
     * @param string $thumbDirectoryKey key prefix the output is stored under
     * @param string $basename          output filename without extension
     */
    public function fromCapture(
        StorageAdapterInterface $adapter,
        UploadedFile $poster,
        string $thumbDirectoryKey,
        string $basename,
    ): ?string {
        $mime = MimeTypeEnum::tryFrom((string) $poster->getMimeType());

        if (!$mime?->isRasterImage()) {
            $this->logger->warning('VideoPosterGenerator: capture is not a raster image', [
                'mime' => $poster->getMimeType(),
            ]);

            return null;
        }

        if (false === @getimagesize($poster->getPathname())) {
            $this->logger->warning('VideoPosterGenerator: capture does not decode');

            return null;
        }

        $posterKey = Path::join($thumbDirectoryKey, sprintf('%s.%s', $basename, $mime->extension()));
        $adapter->writeFromLocalFile($posterKey, $poster->getPathname());

        return $posterKey;
    }

    /**
     * Whether a frame can be extracted from a stored file at all.
     *
     * Callers working through a batch ask once and say so once, rather than
     * reporting every film in the library as a failure when the only thing
     * missing is a binary nobody promised to install.
     */
    public function canExtract(): bool
    {
        return null !== $this->executableFinder->find('ffmpeg');
    }

    /**
     * Extracts a frame from the stored film with `ffmpeg`, or returns null
     * when it is not installed.
     *
     * The frame is written at the source's own resolution: no scaling means
     * the poster's dimensions are the video's dimensions, which is what
     * callers read them back for.
     *
     * @param string $sourceKey         key of the film to read
     * @param string $thumbDirectoryKey key prefix the output is stored under
     * @param string $basename          output filename without extension
     */
    public function fromSource(
        StorageAdapterInterface $adapter,
        string $sourceKey,
        string $thumbDirectoryKey,
        string $basename,
    ): ?string {
        if (!$adapter->exists($sourceKey)) {
            $this->logger->warning('VideoPosterGenerator: source missing', ['key' => $sourceKey]);

            return null;
        }

        $binary = $this->executableFinder->find('ffmpeg');

        if (null === $binary) {
            $this->logger->notice('VideoPosterGenerator: ffmpeg not installed, no poster extracted');

            return null;
        }

        $posterKey = Path::join($thumbDirectoryKey, sprintf('%s.%s', $basename, MimeTypeEnum::Jpeg->extension()));

        $rendered = $this->workspace->readable(
            $adapter,
            $sourceKey,
            fn (string $sourceAbsolute): bool => $this->workspace->target(
                $adapter,
                $posterKey,
                fn (string $output): bool => $this->extract($binary, $sourceAbsolute, $output),
            ),
        );

        return $rendered ? $posterKey : null;
    }

    private function extract(string $binary, string $source, string $output): bool
    {
        // `-ss` before `-i` seeks by keyframe, which is both far cheaper than
        // decoding up to the mark and accurate enough for a still. A clip
        // shorter than the seek point yields nothing, so that case retries
        // from the very first frame rather than leaving the film posterless.
        foreach ([self::SEEK_SECONDS, '0'] as $seek) {
            $process = new Process([
                $binary,
                '-loglevel', 'error',
                '-ss', $seek,
                '-i', $source,
                '-frames:v', '1',
                '-q:v', '3',
                '-y',
                $output,
            ]);
            $process->setTimeout(30.0);

            try {
                $process->run();

                if ($process->isSuccessful() && $this->filesystem->exists($output)) {
                    return true;
                }
            } catch (Throwable $throwable) {
                $this->logger->warning('VideoPosterGenerator: ffmpeg failed', [
                    'error' => $throwable->getMessage(),
                ]);

                return false;
            }
        }

        return false;
    }
}
