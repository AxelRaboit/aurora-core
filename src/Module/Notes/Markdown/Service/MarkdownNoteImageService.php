<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\StoredFileName;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function in_array;
use function sprintf;

/**
 * The images pasted into a note, stored where everything else goes.
 *
 * They went into `var/uploads/notes-markdown/` through a `Filesystem` used
 * directly, without going through the storage layer. It was the only module
 * doing that: the media library, profile photos and contracts all write
 * through `StorageManager`, and the active disk in production has been R2
 * since 12 September. Note images therefore stayed on the server disk, the
 * only ones of their kind.
 *
 * The most telling part: `StorageAreaEnum` already declared the
 * `notes-markdown` zone, added in 0.9.188 so that an access guard could claim
 * the prefix. The zone existed, the adapter existed, the write was not
 * plugged in.
 *
 * **The key carries the owner**: `notes-markdown/{userId}/{uuid}.ext`.
 * It is what holds the access rule, and it holds it better than the old path
 * computation: the controller builds it with the id of the logged-in person,
 * so asking for someone else's image amounts to asking for a key that does
 * not exist. There is no more `realpath` to compare, no more climbing up
 * through `..`, no more root to enforce.
 *
 * Still no Doctrine entity, for the earlier reason: a note image has no alt,
 * no dimensions, no fingerprint to keep. The day quotas or deduplication are
 * needed, that will be another discussion.
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
    public const string FILENAME_PATTERN = '#/suite/notes/markdown/images/([A-Za-z0-9._-]+)#';

    /**
     * A file name as this service makes them: a uuid, a dot, an extension.
     * Everything else is refused before becoming a key.
     *
     * The route that serves an image accepts `[A-Za-z0-9._-]+`, which lets
     * `..` through. On an object key, two dots are just one more segment; on
     * the local disk, they would climb up one level. The local adapter
     * canonicalizes and compares with its root, so it would hold, but an
     * access rule is not made to rest on the vigilance of the layer below.
     *
     * **Two forms, because there were two eras.** Images from before 0.9.230
     * carry a v4 uuid written out in full, with its hyphens; those after carry
     * the thirty-two characters that `StoredFileName` makes, sixteen bytes
     * from the system generator and nothing else. Refusing the first form
     * would make everything pasted before unreadable, and the adoption
     * command cannot rename without rewriting the notes that cite those
     * files.
     */
    private const string FILENAME_SHAPE = '/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.[a-z0-9]{1,5}$/';

    public function __construct(
        private StorageManager $storageManager,
    ) {}

    /**
     * Writes the uploaded file under the person's key, renamed to a uuid so
     * that the name chosen by the browser never touches the storage.
     * Returns the bare name (uuid.ext), which is what the markdown carries.
     *
     * @throws FileException when validation fails (bad MIME, too big)
     */
    public function store(UploadedFile $file, CoreUserInterface|NoteSpaceInterface $user): string
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

        $filename = StoredFileName::withExtension($imageMime->extension());

        // The file is already somewhere on this machine, PHP put it there:
        // passing its path rather than its content makes one copy instead of
        // two. That is what the profile photo does, for the same reason.
        $this->storageManager->active()->writeFromLocalFile(
            $this->keyFor($filename, $user),
            $file->getPathname(),
        );

        return $filename;
    }

    /**
     * The storage key of an image, or null if the name does not have the
     * shape this service produces.
     *
     * Null rather than an exception: the caller turns it into a 404, which is
     * the right answer for a malformed name as well as for an image that
     * does not exist. Telling the two apart would tell the requester whether
     * the file exists for someone else.
     */
    public function keyOrNull(string $filename, CoreUserInterface|NoteSpaceInterface $user): ?string
    {
        if (1 !== preg_match(self::FILENAME_SHAPE, $filename)) {
            return null;
        }

        return $this->keyFor($filename, $user);
    }

    /**
     * Deletes an image, on every disk.
     *
     * On every one, and not only on the active one: an image written before a
     * disk switch still lives on the old one, and deleting it only on the new
     * one would leave it there forever, invisible and billed. It is the same
     * reason that makes the profile photo loop over the disks.
     *
     * Silent on a missing file, so that the cleanup stays replayable.
     */
    public function delete(string $filename, CoreUserInterface|NoteSpaceInterface $user): void
    {
        $key = $this->keyOrNull($filename, $user);

        if (null === $key) {
            return;
        }

        foreach (StorageDiskEnum::cases() as $disk) {
            $this->storageManager->forDisk($disk)->delete($key);
        }
    }

    /**
     * The content of an image, for whoever needs the bytes rather than an
     * HTTP response - the zip export, which stores them next to the markdown.
     *
     * Null when the image does not exist: a note can cite an image deleted in
     * the meantime, and an export that threw for that would refuse to output
     * a whole notebook because of one missing file.
     */
    public function contents(string $filename, CoreUserInterface|NoteSpaceInterface $user): ?string
    {
        $key = $this->keyOrNull($filename, $user);

        if (null === $key) {
            return null;
        }

        foreach ($this->storageManager->all() as $adapter) {
            if ($adapter->exists($key)) {
                return $adapter->read($key);
            }
        }

        return null;
    }

    /**
     * Copies a text's images from one bucket to another.
     *
     * A note that changes space keeps its image addresses as they are
     * - they only carry the file name -, so the file must exist in its new
     * space's bucket. Copy rather than move: an image cited elsewhere in the
     * old space does not disappear from under another note.
     */
    public function copyReferenced(?string $content, CoreUserInterface|NoteSpaceInterface $from, CoreUserInterface|NoteSpaceInterface $to): void
    {
        foreach ($this->extractFilenames($content) as $filename) {
            $bytes = $this->contents($filename, $from);
            $target = $this->keyOrNull($filename, $to);
            if (null === $bytes) {
                continue;
            }

            if (null === $target) {
                continue;
            }

            $this->storageManager->active()->write($target, $bytes);
        }
    }

    /**
     * The text of a copied note, with its images copied under new names.
     *
     * A copy (duplicate, start from a template) does not share its files with
     * the original: removing an image from one deletes it from storage (the
     * orphan image cleanup), and the other would show a broken image. An
     * image that cannot be found keeps its reference as is.
     */
    public function copyAsNew(?string $content, CoreUserInterface|NoteSpaceInterface $from, CoreUserInterface|NoteSpaceInterface $to): ?string
    {
        if (null === $content || '' === $content) {
            return $content;
        }

        /** @var array<string, string> $renamed */
        $renamed = [];

        return (string) preg_replace_callback(self::FILENAME_PATTERN, function (array $match) use (&$renamed, $from, $to): string {
            $filename = $match[1];

            if (!isset($renamed[$filename])) {
                $bytes = $this->contents($filename, $from);
                $copy = StoredFileName::withExtension(mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION)));
                $target = $this->keyOrNull($copy, $to);

                if (null === $bytes || null === $target) {
                    return $match[0];
                }

                $this->storageManager->active()->write($target, $bytes);
                $renamed[$filename] = $copy;
            }

            return str_replace($filename, $renamed[$filename], $match[0]);
        }, $content);
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

    private function keyFor(string $filename, CoreUserInterface|NoteSpaceInterface $user): string
    {
        // The bucket of a space. A personal space's one is the person's
        // historical bucket: no file had to move when notebooks became
        // spaces. A shared space has its own, so that its images show for
        // all its readers and not only for whoever put them there.
        $bucket = $user instanceof NoteSpaceInterface
            ? ($user->getPersonalUser() instanceof CoreUserInterface ? (string) $user->getPersonalUser()->getId() : 'space-'.$user->getId())
            : (string) $user->getId();

        return sprintf('%s/%s/%s', StorageAreaEnum::NotesMarkdown->value, $bucket, $filename);
    }

    /** The bucket of a note's images: that of its space. */
    public function bucketOf(MarkdownNoteInterface $note): NoteSpaceInterface
    {
        return $note->getSpace();
    }
}
