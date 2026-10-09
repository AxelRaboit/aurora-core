<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Core\Locale\Enum\LocaleEnum;
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

use function in_array;
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
 *
 * **Whatever the suite's language.** The folder, the template and the day's
 * note are recognised under their name in every language of the suite: a
 * person who switches the suite to Spanish keeps writing in their "Journal"
 * rather than starting a "Diario" beside it, and finds the morning's note
 * again under its French date. What is written new takes the current
 * language.
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
        private SiteDateFormatter $dateFormatter,
        private TranslatorInterface $translator,
    ) {}

    /** The note of the day `$now` falls on, at the site's time; written if it does not exist yet. */
    public function open(CoreUserInterface $user, DateTimeInterface $now = new DateTimeImmutable()): MarkdownNoteInterface
    {
        $space = $this->spaceAccess->personalSpace($user);
        $folder = $this->journalOf($user, $space);
        $title = $this->titleFor($now);
        $titles = array_map(fn (string $locale): string => $this->dateFormatter->date($now, $locale, 'full'), LocaleEnum::values());

        foreach ($this->noteRepository->findLivingInFolder($space, (int) $folder->getId()) as $note) {
            if (in_array($note->getTitle(), $titles, true)) {
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
                ['{{date}}' => $this->dateFormatter->date($now)],
            );
        }

        return $this->noteManager->create($user, $this->noteInputFactory->fromArray([
            'folderId' => $folder->getId(),
            'title' => $title,
            'content' => sprintf("# %s\n\n", $title),
        ]));
    }

    /**
     * The days of a month that have their note, for the journal's calendar
     * (09/10/2026). Nothing is written: a month without a journal folder
     * simply has no day.
     *
     * @return list<string> `Y-m-d`
     */
    public function daysWithNotes(CoreUserInterface $user, int $year, int $month): array
    {
        $space = $this->spaceAccess->personalSpace($user);
        $names = $this->namesOf('notes.markdown.daily.folder');
        $journal = array_find($this->folderRepository->findLivingInSpace($space), fn($folder): bool => null === $folder->getParent() && $this->knownName($names, $folder->getName()));
        if (null === $journal) {
            return [];
        }

        $titles = [];
        foreach ($this->noteRepository->findLivingInFolder($space, (int) $journal->getId()) as $note) {
            $titles[(string) $note->getTitle()] = true;
        }

        $days = [];
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $year, $month));
        for ($day = $first; (int) $day->format('n') === $month; $day = $day->modify('+1 day')) {
            foreach (LocaleEnum::values() as $locale) {
                if (isset($titles[$this->dateFormatter->date($day, $locale, 'full')])) {
                    $days[] = $day->format('Y-m-d');
                    break;
                }
            }
        }

        return $days;
    }

    /** The day, written out in full in the reader's language: "jeudi 8 octobre 2026". */
    public function titleFor(DateTimeInterface $now): string
    {
        return $this->dateFormatter->date($now, style: 'full');
    }

    /**
     * The "Journal" folder at the root of the personal space, written the
     * first time. A folder in the trash does not count: the journal starts
     * again in a new one rather than writing into the trash.
     */
    private function journalOf(CoreUserInterface $user, NoteSpaceInterface $space): NoteFolderInterface
    {
        $name = $this->translator->trans('notes.markdown.daily.folder');
        $names = $this->namesOf('notes.markdown.daily.folder');

        foreach ($this->folderRepository->findLivingInSpace($space) as $folder) {
            if (null === $folder->getParent() && $this->knownName($names, $folder->getName())) {
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
        $names = $this->namesOf('notes.markdown.daily.title');
        $shared = null;

        foreach ($this->noteRepository->findLivingTemplatesForUser($user) as $template) {
            if (!$this->knownName($names, $template->getTitle())) {
                continue;
            }

            if ($template->getSpace()->isPersonal()) {
                return $template;
            }

            $shared ??= $template;
        }

        return $shared;
    }

    /**
     * A name in every language of the suite, lowercased for the comparison.
     *
     * @return list<string>
     */
    private function namesOf(string $key): array
    {
        return array_values(array_unique(array_map(
            fn (string $locale): string => mb_strtolower($this->translator->trans($key, [], null, $locale)),
            LocaleEnum::values(),
        )));
    }

    /**
     * Typed by somebody: neither the case nor a stray space should hide it.
     *
     * @param list<string> $names
     */
    private function knownName(array $names, ?string $actual): bool
    {
        return in_array(mb_strtolower(mb_trim((string) $actual)), $names, true);
    }
}
