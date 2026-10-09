<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Search;

use Aurora\Module\Notes\Live\Service\NoteLiveHub;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteHistory;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

use function array_slice;
use function array_unique;

/**
 * Replacing a word in several notes at once (10/10/2026), from the search
 * screen.
 *
 * Only in the notes the person may write and that are not locked; the
 * others are counted, never touched. Each changed note goes the way an
 * ordinary save goes: a version in its history first, then the people who
 * have it open are told.
 *
 * Asked first without writing (`preview`), so the screen can say how many
 * occurrences in how many notes before anyone confirms.
 */
final readonly class NoteReplace
{
    /** More notes than a search screen shows at once. */
    public const int MAX_NOTES = 200;

    public function __construct(
        private NoteSpaceAccess $spaceAccess,
        private MarkdownNoteManagerInterface $manager,
        private MarkdownNoteHistory $history,
        private NoteLiveHub $liveHub,
    ) {}

    /**
     * @param list<int> $ids
     *
     * @return array{notes: list<array{id: int, title: string, count: int}>, occurrences: int, skipped: int}
     */
    public function preview(CoreUserInterface $user, array $ids, string $find, bool $exact): array
    {
        return $this->walk($user, $ids, $find, '', $exact, false);
    }

    /**
     * @param list<int> $ids
     *
     * @return array{notes: list<array{id: int, title: string, count: int}>, occurrences: int, skipped: int}
     */
    public function apply(CoreUserInterface $user, array $ids, string $find, string $replacement, bool $exact): array
    {
        return $this->walk($user, $ids, $find, $replacement, $exact, true);
    }

    /**
     * @param list<int> $ids
     *
     * @return array{notes: list<array{id: int, title: string, count: int}>, occurrences: int, skipped: int}
     */
    private function walk(CoreUserInterface $user, array $ids, string $find, string $replacement, bool $exact, bool $write): array
    {
        $notes = [];
        $occurrences = 0;
        $skipped = 0;

        foreach (array_slice(array_unique($ids), 0, self::MAX_NOTES) as $id) {
            $note = $this->spaceAccess->writableNote($user, $id);
            if (!$note instanceof MarkdownNoteInterface || $note->isLocked()) {
                ++$skipped;
                continue;
            }

            [$content, $count] = NoteSearchText::replace((string) $note->getContent(), $find, $replacement, $exact);
            if (0 === $count) {
                continue;
            }

            if ($write) {
                $this->history->beforeChange($note, $note->getTitle(), $content, $user);
                $this->manager->updateText($note, $note->getTitle(), $content);
                $this->liveHub->publishChanged($note, $user->getName());
            }

            $notes[] = ['id' => (int) $note->getId(), 'title' => (string) $note->getTitle(), 'count' => $count];
            $occurrences += $count;
        }

        return ['notes' => $notes, 'occurrences' => $occurrences, 'skipped' => $skipped];
    }
}
