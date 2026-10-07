<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use RuntimeException;
use ZipArchive;

use function count;
use function implode;
use function preg_replace;
use function sprintf;

/**
 * A person's notes, as they could take them away.
 *
 * **Markdown, and nothing else.** The module stores encrypted notes in a
 * database; what comes out is a tree of `.md` files that a text editor opens,
 * that Obsidian reads, and that will outlive Aurora. It is the counterpart of
 * the lock-in a notebook in a database represents, and it is only worth
 * anything if it is complete: the tags therefore travel at the top of the
 * file, in the front matter those same tools know.
 *
 * **A folder is a folder, a note is a file.** The old convention, Obsidian's,
 * wrote a note that had children as two entries with the same name, a `.md`
 * and a directory, for lack of another way to say that an object was both at
 * once. Folders now exist, and the archive simply says what it contains.
 */
final readonly class MarkdownNoteArchive
{
    /**
     * The folder where the archive stores images, at its root.
     *
     * A single one, and not one per note: an image can be cited by two notes,
     * and copying it twice would double the zip's weight for nothing.
     * The name starts with an underscore so that it stands out from a folder
     * the person might have created - `_images` is not a name you give to a
     * notebook.
     */
    private const string IMAGE_DIR = '_images';

    public function __construct(
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
        private MarkdownNoteImageService $images,
    ) {}

    /**
     * The whole notebook in a zip, written to a temporary file.
     *
     * Written to disk rather than kept in memory: `ZipArchive` can only work
     * on a file, and a notebook of several thousand notes has no reason to
     * sit twice in RAM to be downloaded.
     *
     * @param ?NoteSpaceInterface $only a single space, stored at the root of the archive; everything the person reads otherwise
     *
     * @return string the path of the zip, to be deleted by the caller
     */
    public function zipFor(CoreUserInterface $user, ?NoteSpaceInterface $only = null): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-notes-');

        $zip = new ZipArchive();

        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException("impossible d'ouvrir l'archive");
        }

        $notes = $this->notes->findAllWithContentForUser($user);

        // Each shared space goes into its own folder, under its name: at the
        // root of the archive, its folders would mix with those of the
        // personal notebook, and a reimport would file everything at home
        // without warning. The root of a space is the negative key of its id.
        /** @var array<int, list<MarkdownNoteInterface>> $notesByFolder */
        $notesByFolder = [];
        /** @var array<int, NoteSpaceInterface> $spaces */
        $spaces = [];
        foreach ($notes as $note) {
            if ($only instanceof NoteSpaceInterface && $note->getSpace()->getId() !== $only->getId()) {
                continue;
            }

            $spaces[(int) $note->getSpace()->getId()] = $note->getSpace();
            $notesByFolder[$note->getFolder()?->getId() ?? -(int) $note->getSpace()->getId()][] = $note;
        }

        /** @var array<int, list<NoteFolderInterface>> $foldersByParent */
        $foldersByParent = [];
        foreach ($this->folders->findAllForUser($user) as $folder) {
            if ($only instanceof NoteSpaceInterface && $folder->getSpace()->getId() !== $only->getId()) {
                continue;
            }

            $spaces[(int) $folder->getSpace()->getId()] = $folder->getSpace();
            $foldersByParent[$folder->getParent()?->getId() ?? -(int) $folder->getSpace()->getId()][] = $folder;
        }

        // An empty notebook would give a zip with no entry, which some tools
        // refuse to open. One line is enough to make it valid and to say why
        // it is empty.
        if ([] === $notesByFolder && [] === $foldersByParent) {
            $zip->addFromString('notes.md', "# Aucune note\n");
        }

        // The images already written into the archive, so as to add none of
        // them twice: two notes can cite the same one.
        $ajoutees = [];

        $seenSpaces = [];
        foreach ($spaces as $id => $space) {
            // A single space requested: it is the whole archive, at its root.
            if ($space->isPersonal() || $only instanceof NoteSpaceInterface) {
                $this->addBranch($zip, $notesByFolder, $foldersByParent, -$id, '', $user, $ajoutees);

                continue;
            }

            $spaceDirectory = $this->uniqueName($this->safeName((string) $space->getName(), sprintf('espace-%d', $id)), $seenSpaces);
            $zip->addEmptyDir($spaceDirectory);
            $this->addBranch($zip, $notesByFolder, $foldersByParent, -$id, $spaceDirectory.'/', $user, $ajoutees);
        }

        $zip->close();

        return $path;
    }

    /**
     * A single note, ready to be saved.
     *
     * Its images keep the back office address: a `.md` downloaded alone has
     * no neighbouring folder to put them in, and rewriting the link to a file
     * that comes with nothing would be worse than an address that asks you
     * to log in. It is the notebook archive that takes the images along.
     */
    public function fileFor(MarkdownNoteInterface $note): string
    {
        $tags = $note->getTags();
        $content = (string) $note->getContent();

        if (0 === count($tags)) {
            return $content;
        }

        return sprintf("---\ntags: [%s]\n---\n\n%s", implode(', ', $tags), $content);
    }

    /** A note's file name, without the folder or the extension. */
    public function nameOf(MarkdownNoteInterface $note): string
    {
        return $this->safeName((string) $note->getTitle(), sprintf('note-%d', $note->getId()));
    }

    /**
     * @param array<int, list<MarkdownNoteInterface>> $notesByFolder
     * @param array<int, list<NoteFolderInterface>>   $foldersByParent
     * @param array<string, true>                     $ajoutees        images already in the archive
     */
    private function addBranch(
        ZipArchive $zip,
        array $notesByFolder,
        array $foldersByParent,
        int $folderId,
        string $prefix,
        CoreUserInterface $user,
        array &$ajoutees,
    ): void {
        $seenFiles = [];

        foreach ($notesByFolder[$folderId] ?? [] as $note) {
            $name = $this->uniqueName($this->nameOf($note), $seenFiles);
            $zip->addFromString($prefix.$name.'.md', $this->body($note, $prefix, $user, $zip, $ajoutees));
        }

        $seenFolders = [];

        foreach ($foldersByParent[$folderId] ?? [] as $folder) {
            $name = $this->uniqueName(
                $this->safeName((string) $folder->getName(), sprintf('dossier-%d', $folder->getId())),
                $seenFolders,
            );

            // An empty folder would disappear from the archive, since nothing
            // writes a file in it. Declared explicitly, it survives the round
            // trip like the rest of the filing.
            $zip->addEmptyDir($prefix.$name);

            $this->addBranch($zip, $notesByFolder, $foldersByParent, (int) $folder->getId(), $prefix.$name.'/', $user, $ajoutees);
        }
    }

    /**
     * What a file system refuses, plus the characters that turn a name into
     * a path. The rest of the accents and spaces is kept: it is the title the
     * person wrote.
     */
    private function safeName(string $raw, string $fallback): string
    {
        $name = mb_trim($raw);

        if ('' === $name) {
            $name = $fallback;
        }

        return (string) preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#', '-', $name);
    }

    /**
     * Two notes can carry the same title; two files of a folder cannot. The
     * second one takes a suffix rather than overwriting the first.
     *
     * @param array<string, int> $seen
     */
    private function uniqueName(string $name, array &$seen): string
    {
        if (!isset($seen[$name])) {
            $seen[$name] = 1;

            return $name;
        }

        return sprintf('%s (%d)', $name, ++$seen[$name]);
    }

    /**
     * The front matter, then the text, images included.
     *
     * @param array<string, true> $ajoutees
     */
    private function body(
        MarkdownNoteInterface $note,
        string $prefix,
        CoreUserInterface $user,
        ZipArchive $zip,
        array &$ajoutees,
    ): string {
        $tags = $note->getTags();
        $content = $this->withImages((string) $note->getContent(), $prefix, $this->images->bucketOf($note), $zip, $ajoutees);

        if (0 === count($tags)) {
            return $content;
        }

        return sprintf("---\ntags: [%s]\n---\n\n%s", implode(', ', $tags), $content);
    }

    /**
     * Copies into the archive the images the note cites, and replaces their
     * address with a relative path.
     *
     * Without that, an exported notebook came out with images pointing to the
     * back office: opened in Obsidian or in any editor, the text arrived whole
     * and the images were broken icons, or worse, asked you to log in. An
     * archive is meant to be self-sufficient.
     *
     * The path is relative to the note, so it goes up as many levels as its
     * folder is deep: a note at the root writes `_images/x.png`, a note two
     * levels down `../../_images/x.png`. That is what every markdown reader
     * resolves, Obsidian included.
     *
     * A missing image - deleted from storage since the note cited it - keeps
     * its original address. Refusing to export the whole notebook for one
     * missing file would be a disproportionate punishment, and the address
     * as is at least says what was missing.
     *
     * @param array<string, true> $ajoutees
     */
    private function withImages(
        string $content,
        string $prefix,
        CoreUserInterface|NoteSpaceInterface $user,
        ZipArchive $zip,
        array &$ajoutees,
    ): string {
        $filenames = $this->images->extractFilenames($content);

        if ([] === $filenames) {
            return $content;
        }

        $profondeur = mb_substr_count($prefix, '/');
        $remontee = str_repeat('../', $profondeur);

        foreach ($filenames as $filename) {
            if (!isset($ajoutees[$filename])) {
                $octets = $this->images->contents($filename, $user);

                if (null === $octets) {
                    continue;
                }

                $zip->addFromString(self::IMAGE_DIR.'/'.$filename, $octets);
                $ajoutees[$filename] = true;
            }

            $content = str_replace(
                '/suite/notes/markdown/images/'.$filename,
                $remontee.self::IMAGE_DIR.'/'.$filename,
                $content,
            );
        }

        return $content;
    }
}
