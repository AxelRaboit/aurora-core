<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

use function in_array;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Filesystem-backed image storage for markdown notes. Files are kept
 * **outside** the public document root (`var/uploads/notes-markdown/`)
 * because they must be served through the controller for per-user auth
 * - direct nginx serving would let any logged-in user fetch another
 * user's images by guessing the URL.
 *
 * Layout: `{storageDir}/{userId}/{uuid}.{ext}`.
 *
 * The service deliberately stays "path B" (no Doctrine entity): notes
 * markdown images don't need metadata (alt, dimensions, hash, …) and a
 * filesystem layout keeps the surface tiny. Migrate to an entity later
 * if quotas, deduplication, or EXIF inspection enter scope.
 */
final readonly class MarkdownNoteImageService
{
    /** Hard cap on a single uploaded file. */
    public const int MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Domain allowlist of MIME types accepted by the markdown notes
     * editor. Subset of {@see MimeTypeEnum} - we intentionally exclude
     * SVG (XSS via embedded scripts) and PDF (not an inline image).
     *
     * @var list<MimeTypeEnum>
     */
    private const array ALLOWED_MIME_TYPES = [
        MimeTypeEnum::Png,
        MimeTypeEnum::Jpeg,
        MimeTypeEnum::Webp,
        MimeTypeEnum::Gif,
    ];

    /**
     * Regex matching the controller's serve URL inside markdown content.
     * Capture group 1 is the bare filename (uuid.ext). Used by the
     * manager's orphan-cleanup hook to diff old vs new note content.
     */
    public const string FILENAME_PATTERN = '#/backend/notes/markdown/images/([A-Za-z0-9._-]+)#';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/notes-markdown')]
        private string $storageDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    /**
     * Move the uploaded file under the user's directory, renamed to a
     * uuid so the client-supplied basename never lands on disk. Returns
     * the bare filename (uuid.ext) for embedding into markdown - the
     * controller knows how to recover the absolute path from it.
     *
     * @throws FileException when validation fails (bad MIME, too big)
     */
    public function store(UploadedFile $file, CoreUserInterface $user): string
    {
        $size = $file->getSize();
        if (false !== $size && $size > self::MAX_FILE_SIZE) {
            throw new FileException(sprintf('Image exceeds max size of %d bytes.', self::MAX_FILE_SIZE));
        }

        $mime = $file->getMimeType() ?? '';
        $imageMime = MimeTypeEnum::tryFrom($mime);
        if (null === $imageMime || !in_array($imageMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new FileException(sprintf('Unsupported MIME type "%s".', $mime));
        }

        $filename = sprintf('%s.%s', Uuid::v4()->toRfc4122(), $imageMime->extension());

        $userDir = $this->userDir($user);
        $this->filesystem->mkdir($userDir, 0o755);

        $file->move($userDir, $filename);

        return $filename;
    }

    /**
     * Absolute path for serving the given filename to the given user.
     * Path traversal guard: the resolved `realpath` must stay under the
     * user's directory - otherwise a `..`-laden filename could escape
     * upward (e.g. read another user's images).
     *
     * @throws RuntimeException when the file is missing or outside the user dir
     */
    public function path(string $filename, CoreUserInterface $user): string
    {
        $userDir = $this->userDir($user);
        $candidate = Path::join($userDir, $filename);
        $real = realpath($candidate);

        if (false === $real) {
            throw new RuntimeException(sprintf('Image not found: %s', $filename));
        }

        $userRoot = realpath($userDir);
        if (false === $userRoot || !str_starts_with($real, $userRoot.DIRECTORY_SEPARATOR) && $real !== $userRoot) {
            throw new RuntimeException(sprintf('Image path escapes user directory: %s', $filename));
        }

        return $real;
    }

    /**
     * Delete a single image. Silently no-ops on a missing file so the
     * cleanup hook stays idempotent even if a previous run partially
     * deleted state (or if the same orphan appears in two simultaneous
     * updates).
     */
    public function delete(string $filename, CoreUserInterface $user): void
    {
        try {
            $real = $this->path($filename, $user);
        } catch (RuntimeException) {
            return;
        }

        $this->filesystem->remove($real);
    }

    /**
     * Extract every image filename referenced by a markdown blob. Used
     * by the orphan-cleanup hook to compute set differences between
     * an old and a new content version.
     *
     * @return list<string>
     */
    public function extractFilenames(?string $content): array
    {
        if (null === $content || '' === $content) {
            return [];
        }

        if (0 === preg_match_all(self::FILENAME_PATTERN, $content, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    private function userDir(CoreUserInterface $user): string
    {
        return Path::join($this->storageDir, (string) $user->getId());
    }
}
