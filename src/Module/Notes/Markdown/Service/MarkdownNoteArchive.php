<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\View\MarkdownNoteDisplay;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use RuntimeException;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;
use ZipArchive;

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
        private MarkdownNoteRepository $noteRepository,
        private NoteFolderRepository $folderRepository,
        private NoteSpaceRepository $spaceRepository,
        private MarkdownNoteImageService $imageService,
        private TranslatorInterface $translator,
        // Last and optional, so a project that builds the archive by hand
        // keeps working: without it, a person property is written by id.
        private ?MarkdownNoteDisplay $display = null,
    ) {}

    /**
     * The whole notebook in a zip, written to a temporary file.
     *
     * Written to disk rather than kept in memory: `ZipArchive` can only work
     * on a file, and a notebook of several thousand notes has no reason to
     * sit twice in RAM to be downloaded.
     *
     * **Everything: one folder per space, the personal one included.** Every
     * space the person can read gets its folder at the root, under the name
     * the panel shows, even when it is empty. The personal space used to sit
     * at the root, mixed with the shared spaces' folders: a personal folder
     * named like a shared space then wrote into the same folder of the zip,
     * and a reimport filed both together.
     *
     * **One space, or one folder:** its content at the root of the archive,
     * whose name says what it holds ({@see self::fileNameFor()}).
     *
     * @param NoteSpaceInterface|NoteFolderInterface|null $root what to take; everything the person reads when null
     *
     * @return string the path of the zip, to be deleted by the caller
     */
    public function zipFor(CoreUserInterface $user, NoteSpaceInterface|NoteFolderInterface|null $root = null): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-notes-');

        $zip = new ZipArchive();

        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException("impossible d'ouvrir l'archive");
        }

        $onlySpace = $root instanceof NoteFolderInterface ? $root->getSpace() : $root;

        // The root of a space is the negative key of its id.
        /** @var array<int, list<MarkdownNoteInterface>> $notesByFolder */
        $notesByFolder = [];
        foreach ($this->noteRepository->findAllWithContentForUser($user) as $note) {
            if ($onlySpace instanceof NoteSpaceInterface && $note->getSpace()->getId() !== $onlySpace->getId()) {
                continue;
            }

            $notesByFolder[$note->getFolder()?->getId() ?? -(int) $note->getSpace()->getId()][] = $note;
        }

        /** @var array<int, list<NoteFolderInterface>> $foldersByParent */
        $foldersByParent = [];
        foreach ($this->folderRepository->findAllForUser($user) as $folder) {
            if ($onlySpace instanceof NoteSpaceInterface && $folder->getSpace()->getId() !== $onlySpace->getId()) {
                continue;
            }

            $foldersByParent[$folder->getParent()?->getId() ?? -(int) $folder->getSpace()->getId()][] = $folder;
        }

        // The images already written into the archive, so as to add none of
        // them twice: two notes can cite the same one.
        $ajoutees = [];

        if ($root instanceof NoteFolderInterface) {
            $this->addBranch($zip, $notesByFolder, $foldersByParent, (int) $root->getId(), '', $user, $ajoutees);
        } elseif ($root instanceof NoteSpaceInterface) {
            $this->addBranch($zip, $notesByFolder, $foldersByParent, -(int) $root->getId(), '', $user, $ajoutees);
        } else {
            // From the list of readable spaces, not from the notes found: an
            // empty space had no folder in the archive.
            $seenSpaces = [];
            foreach ($this->spaceRepository->findReadableFor($user) as $space) {
                $spaceDirectory = $this->uniqueName($this->safeName($this->spaceLabel($space), sprintf('espace-%d', $space->getId())), $seenSpaces);
                $zip->addEmptyDir($spaceDirectory);
                $this->addBranch($zip, $notesByFolder, $foldersByParent, -(int) $space->getId(), $spaceDirectory.'/', $user, $ajoutees);
            }
        }

        // An empty space or folder would give a zip with no entry, which some
        // tools refuse to open. One line is enough to make it valid and to
        // say why it is empty.
        if (0 === $zip->numFiles) {
            $zip->addFromString('notes.md', "# Aucune note\n");
        }

        $zip->close();

        return $path;
    }

    /**
     * The archive's name, which says what it holds.
     *
     * Always `notes-2026-10-07.zip` before: two exports of the same day, a
     * space then a folder, could not be told apart in the downloads folder.
     */
    public function fileNameFor(NoteSpaceInterface|NoteFolderInterface|null $root = null): string
    {
        $label = match (true) {
            $root instanceof NoteSpaceInterface => $this->spaceLabel($root),
            $root instanceof NoteFolderInterface => (string) $root->getName(),
            default => '',
        };

        $slug = mb_strtolower(new AsciiSlugger()->slug($label)->toString());

        return '' === $slug
            ? sprintf('notes-%s.zip', date('Y-m-d'))
            : sprintf('notes-%s-%s.zip', $slug, date('Y-m-d'));
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
        return $this->withFrontMatter($note, (string) $note->getContent());
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
     * A space's name as the panel shows it.
     *
     * The personal space is shown as « Mon espace de notes », whatever name
     * it carries in the database: the archive uses the same words, in the
     * reader's language.
     */
    private function spaceLabel(NoteSpaceInterface $space): string
    {
        return $space->isPersonal()
            ? $this->translator->trans('notes.markdown.spaces.my_space')
            : (string) $space->getName();
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
        $content = $this->withImages((string) $note->getContent(), $prefix, $this->imageService->bucketOf($note), $zip, $ajoutees);

        return $this->withFrontMatter($note, $content);
    }

    /**
     * The tags, the emoji and the properties at the top of the file
     * (09/10/2026), Obsidian's way; nothing at all for a note without any.
     */
    private function withFrontMatter(MarkdownNoteInterface $note, string $content): string
    {
        $properties = $this->display instanceof MarkdownNoteDisplay ? $this->display->describe($note)['properties'] : $note->getProperties();

        return NoteFrontMatter::write($note->getTags(), $note->getIcon(), $properties, $content);
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
        $filenames = $this->imageService->extractFilenames($content);

        if ([] === $filenames) {
            return $content;
        }

        $profondeur = mb_substr_count($prefix, '/');
        $remontee = str_repeat('../', $profondeur);

        foreach ($filenames as $filename) {
            if (!isset($ajoutees[$filename])) {
                $octets = $this->imageService->contents($filename, $user);

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
