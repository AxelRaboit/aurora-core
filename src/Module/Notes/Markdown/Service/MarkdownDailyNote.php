<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Configuration\Setting\Service\SiteDateFormatter;
use Aurora\Module\Notes\Folder\Dto\NoteFolderInputFactoryInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Manager\NoteFolderManagerInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function mb_strtolower;
use function mb_trim;
use function sprintf;

/**
 * Today's note, the way Obsidian keeps a journal: one note per day, named
 * after the day, filed in a "Journal" folder at the root of the personal
 * space.
 *
 * **Opened, never duplicated.** The button is pressed several times a day:
 * the first press writes the note, the next ones find it again by its title
 * in that folder. The folder is found again by its name, and written the
 * first time. Both titles are encrypted, so both lookups happen here in PHP,
 * on one folder's notes and one space's folders.
 *
 * **Always the personal space**, whatever space the library shows: a journal
 * is somebody's own, and nothing here writes into a space shared with others.
 *
 * The text comes from a template named like the button ("Note du jour") when
 * the person can read one, with `{{date}}` filled in as any template's;
 * otherwise a heading with the day, ready to be written under.
 */
final readonly class MarkdownDailyNote
{
    public function __construct(
        private MarkdownNoteManagerInterface $noteManager,
        private NoteFolderManagerInterface $folderManager,
        private MarkdownNoteInputFactoryInterface $noteInputFactory,
        private NoteFolderInputFactoryInterface $folderInputFactory,
        private MarkdownNoteRepository $noteRepository,
        private NoteFolderRepository $folderRepository,
        private NoteSpaceAccess $spaceAccess,
        private SiteDateFormatter $dates,
        private TranslatorInterface $translator,
    ) {}

    /** The note of the day `$now` falls on, at the site's time; written if it does not exist yet. */
    public function open(CoreUserInterface $user, DateTimeInterface $now = new DateTimeImmutable()): MarkdownNoteInterface
    {
        $space = $this->spaceAccess->personalSpace($user);
        $folder = $this->journalOf($user, $space);
        $title = $this->titleFor($now);

        foreach ($this->noteRepository->findLivingInFolder($space, (int) $folder->getId()) as $note) {
            if ($title === $note->getTitle()) {
                return $note;
            }
        }

        $template = $this->templateFor($user);
        if ($template instanceof MarkdownNoteInterface) {
            return $this->noteManager->createFromTemplate(
                $user,
                $template,
                $folder,
                $space,
                $title,
                ['{{date}}' => $this->dates->date($now)],
            );
        }

        return $this->noteManager->create($user, $this->noteInputFactory->fromArray([
            'folderId' => $folder->getId(),
            'title' => $title,
            'content' => sprintf("# %s\n\n", $title),
        ]));
    }

    /** The day, written out in full in the reader's language: "jeudi 8 octobre 2026". */
    public function titleFor(DateTimeInterface $now): string
    {
        return $this->dates->date($now, style: 'full');
    }

    /**
     * The "Journal" folder at the root of the personal space, written the
     * first time. A folder in the trash does not count: the journal starts
     * again in a new one rather than writing into the trash.
     */
    private function journalOf(CoreUserInterface $user, NoteSpaceInterface $space): NoteFolderInterface
    {
        $name = $this->translator->trans('notes.markdown.daily.folder');

        foreach ($this->folderRepository->findLivingInSpace($space) as $folder) {
            if (null === $folder->getParent() && $this->sameName($name, $folder->getName())) {
                return $folder;
            }
        }

        return $this->folderManager->create($user, $this->folderInputFactory->fromArray([
            'name' => $name,
            'spaceId' => $space->getId(),
        ]));
    }

    /**
     * A template the person can read, named like the button. One of their
     * own comes before one from a shared space: it is theirs they meant.
     */
    private function templateFor(CoreUserInterface $user): ?MarkdownNoteInterface
    {
        $name = $this->translator->trans('notes.markdown.daily.title');
        $shared = null;

        foreach ($this->noteRepository->findLivingTemplatesForUser($user) as $template) {
            if (!$this->sameName($name, $template->getTitle())) {
                continue;
            }

            if ($template->getSpace()->isPersonal()) {
                return $template;
            }

            $shared ??= $template;
        }

        return $shared;
    }

    /** Typed by somebody: neither the case nor a stray space should hide it. */
    private function sameName(string $expected, ?string $actual): bool
    {
        return mb_strtolower($expected) === mb_strtolower(mb_trim((string) $actual));
    }
}
