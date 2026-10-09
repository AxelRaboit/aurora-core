<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Dto\NoteFolderInput;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Manager\NoteFolderManagerInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInput;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use ZipArchive;

use function array_filter;
use function array_pop;
use function array_shift;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function mb_trim;
use function pathinfo;
use function str_ends_with;
use function str_starts_with;

/**
 * Markdown files, turned back into a notebook.
 *
 * **The return leg of the trip {@see MarkdownNoteArchive} makes.** A zip
 * exported then reimported must give back the same tree, the same titles and
 * the same tags: it is the only proof that an export is something other than
 * a pile of files.
 *
 * **A directory is a folder, a `.md` is a note.** There is no special case
 * left to catch: the archive format says which of the two is which, where the
 * old convention made one name both a file and a directory for a single
 * note.
 *
 * **Nothing is overwritten.** A note with the same name already exists? A
 * second one is created next to it. Merging would mean deciding what wins, on
 * a screen where nobody asked for anything like that; adding is the only
 * action that loses nothing, and the trash catches the duplicate.
 *
 * Everything goes through the managers, never through the entities: an
 * imported note is a note like any other, with its log and its positions.
 */
final readonly class MarkdownNoteImporter
{
    /**
     * The extensions an archive can carry as an image.
     *
     * The same list as the image service's, minus the detail: it is the one
     * that really decides, by reading the file's actual type. Here we only
     * decide which zip entries are worth opening, so as not to try importing
     * a two hundred page PDF.
     *
     * @var list<string>
     */
    private const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public function __construct(
        private MarkdownNoteManagerInterface $notes,
        private NoteFolderManagerInterface $folders,
        private MarkdownNoteImageService $imageService,
        private TranslatorInterface $translator,
        /** @var list<string> */
        #[Autowire(param: 'kernel.enabled_locales')]
        private array $locales = [],
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    /**
     * Imports a file, `.md` or `.zip`, into the given folder.
     *
     * @return int the number of notes and folders created
     */
    /**
     * @param ?NoteSpaceInterface $space the root to import into when there is no folder; the personal space otherwise
     */
    public function import(CoreUserInterface $user, UploadedFile $file, ?NoteFolderInterface $folder, ?NoteSpaceInterface $space = null): int
    {
        $name = $file->getClientOriginalName();
        // A folder imposes its space; without one, the requested root.
        $space = $folder?->getSpace() ?? $space;

        if (str_ends_with(mb_strtolower($name), '.zip')) {
            return $this->importZip($user, $file, $folder, $space);
        }

        $this->createNote($user, $folder, $space, $this->titleOf($name), (string) file_get_contents($file->getPathname()));

        return 1;
    }

    /**
     * A zip, folder by folder.
     *
     * The archive's directories are created as paths are met, once each: a
     * folder crossed by ten files is one folder, not ten. Since the format
     * does not guarantee the order of entries, a directory declared empty
     * and a directory deduced from a path end up as the same folder.
     */
    private function importZip(CoreUserInterface $user, UploadedFile $file, ?NoteFolderInterface $folder, ?NoteSpaceInterface $space): int
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($file->getPathname())) {
            return 0;
        }

        // A full export files the personal notebook under « Mon espace de
        // notes/ » ({@see MarkdownNoteArchive::zipFor()}). Poured back into
        // that same notebook, the folder would only add a level: its content
        // goes to the root instead. Only there - imported into a folder or a
        // shared space, it stays the folder it is - and the shared spaces'
        // folders always stay folders, so that an import never pours notes
        // unannounced into a space others read.
        $personalRoots = !$folder instanceof NoteFolderInterface && (!$space instanceof NoteSpaceInterface || $space->isPersonal()) ? $this->personalLabels() : [];

        /** @var array<string, NoteFolderInterface> $byPath */
        $byPath = [];
        $created = 0;
        /** @var list<MarkdownNoteInput> $notes written together once the folders exist */
        $notes = [];

        // Images first, because a note that cites one needs its new address
        // at the moment it is written.
        // Images go into the bucket of the destination space, so that all its
        // readers see them.
        $imported = $this->importImages($zip, $space ?? $user);

        for ($entryIndex = 0; $entryIndex < $zip->numFiles; ++$entryIndex) {
            $entry = (string) $zip->getNameIndex($entryIndex);

            if (str_starts_with($entry, '__MACOSX/')) {
                continue;
            }

            $isDirectory = str_ends_with($entry, '/');

            if (!$isDirectory && !str_ends_with(mb_strtolower($entry), '.md')) {
                continue;
            }

            $segments = array_values(array_filter(explode('/', $entry), static fn (string $part): bool => '' !== $part));

            if ([] === $segments) {
                continue;
            }

            $fileName = $isDirectory ? null : array_pop($segments);

            if (isset($segments[0]) && in_array($segments[0], $personalRoots, true)) {
                array_shift($segments);

                if (null === $fileName && [] === $segments) {
                    continue;
                }
            }

            $under = $folder;
            $path = '';

            foreach ($segments as $segment) {
                $path .= '/'.$segment;

                if (!isset($byPath[$path])) {
                    $byPath[$path] = $this->folders->create($user, new NoteFolderInput(
                        name: $segment,
                        parentId: $under?->getId(),
                        spaceId: null === $under ? $space?->getId() : null,
                    ));
                    ++$created;
                }

                $under = $byPath[$path];
            }

            if (null === $fileName) {
                continue;
            }

            $notes[] = $this->noteInput($under, $space, $this->titleOf($fileName), $this->relink((string) $zip->getFromIndex($entryIndex), $imported));
            ++$created;
        }

        $zip->close();

        $this->notes->createMany($user, $notes);

        return $created;
    }

    /**
     * The personal space's name in every language the site speaks.
     *
     * The export writes it in the reader's language: an archive made in
     * English must unwrap the same way when it comes back in French.
     *
     * @return list<string>
     */
    private function personalLabels(): array
    {
        $key = 'notes.markdown.spaces.my_space';
        $labels = [$this->translator->trans($key)];

        foreach ($this->locales as $locale) {
            $labels[] = $this->translator->trans($key, [], null, $locale);
        }

        return array_values(array_unique($labels));
    }

    /**
     * Takes back the images the archive carries, and returns the table that
     * says which file name became which address.
     *
     * Keyed by their **base name** and not by their path: our archives store
     * them in `_images/`, Obsidian in an attachments folder each person names
     * as they like, and a note points to it through a relative path that
     * depends on its depth. The base name is what both have in common. Two
     * images with the same name in two different folders would step on each
     * other; that is the price, and it is lower than importing nothing at all.
     *
     * An image refused by the service - too big, or of a type not accepted,
     * an SVG for example - is simply skipped. The notebook import goes on, and
     * the note will keep a dead link rather than not exist.
     *
     * @return array<string, string> base name in the archive => address to write
     */
    private function importImages(ZipArchive $zip, CoreUserInterface|NoteSpaceInterface $bucket): array
    {
        $imported = [];

        for ($entryIndex = 0; $entryIndex < $zip->numFiles; ++$entryIndex) {
            $entry = (string) $zip->getNameIndex($entryIndex);
            if (str_starts_with($entry, '__MACOSX/')) {
                continue;
            }

            if (str_ends_with($entry, '/')) {
                continue;
            }

            $base = basename($entry);
            $extension = mb_strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }

            if (isset($imported[$base])) {
                continue;
            }

            $octets = $zip->getFromIndex($entryIndex);
            if (false === $octets) {
                continue;
            }

            if ('' === $octets) {
                continue;
            }

            // Through a temporary file: the service validates the actual type
            // by reading the file, which cannot be asked of it on an
            // in-memory string. The fifth argument puts the object in test
            // mode, without which Symfony refuses a file that PHP did not
            // itself receive from a form.
            $temporaire = (string) tempnam(sys_get_temp_dir(), 'aurora-note-image-');
            $this->filesystem->dumpFile($temporaire, $octets);

            try {
                $filename = $this->imageService->store(
                    new UploadedFile($temporaire, $base, null, null, true),
                    $bucket,
                );
                $imported[$base] = '/suite/notes/markdown/images/'.$filename;
            } catch (FileException) {
                // Skipped, for the reason given above.
            } finally {
                $this->filesystem->remove($temporaire);
            }
        }

        return $imported;
    }

    /**
     * Replaces, in a note's text, the paths to the archive's images with the
     * addresses they took here.
     *
     * What the export wrote in the other direction: `_images/x.png` becomes a
     * back office address again. The path is compared by its base name, so
     * `../../_images/x.png` and `attachments/x.png` both land on the same
     * entry.
     *
     * An absolute address is left as is: it does not designate a file of the
     * archive.
     *
     * @param array<string, string> $imported
     */
    private function relink(string $content, array $imported): string
    {
        if ([] === $imported) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)([^)]*)\)/',
            static function (array $match) use ($imported): string {
                $cible = $match[2];

                if (str_starts_with($cible, 'http://') || str_starts_with($cible, 'https://') || str_starts_with($cible, 'data:')) {
                    return $match[0];
                }

                $base = basename(explode('#', explode('?', $cible)[0])[0]);

                if (!isset($imported[$base])) {
                    return $match[0];
                }

                return sprintf('![%s](%s%s)', $match[1], $imported[$base], $match[3]);
            },
            $content,
        );
    }

    private function createNote(
        CoreUserInterface $user,
        ?NoteFolderInterface $folder,
        ?NoteSpaceInterface $space,
        string $title,
        string $raw,
    ): void {
        $this->notes->create($user, $this->noteInput($folder, $space, $title, $raw));
    }

    private function noteInput(?NoteFolderInterface $folder, ?NoteSpaceInterface $space, string $title, string $raw): MarkdownNoteInput
    {
        // The tags, the emoji and the properties of the front matter
        // (09/10/2026): ours, and Obsidian's, which writes the same way.
        $front = NoteFrontMatter::read($raw);

        return new MarkdownNoteInput(
            folderId: $folder?->getId(),
            title: $title,
            content: $front['content'],
            tags: $front['tags'],
            spaceId: $folder instanceof NoteFolderInterface ? null : $space?->getId(),
            icon: $front['icon'],
            properties: $front['properties'],
        );
    }

    /** The file name, without its extension, as the title. */
    private function titleOf(string $fileName): string
    {
        $title = pathinfo($fileName, PATHINFO_FILENAME);

        return '' === mb_trim($title) ? 'Sans titre' : $title;
    }
}
