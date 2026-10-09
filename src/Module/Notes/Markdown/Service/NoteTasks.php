<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

use function count;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_replace_callback;

/**
 * Every checkbox of every note a person reads, in one list (09/10/2026), as
 * Obsidian's Tasks and Craft's task view gather them.
 *
 * **Numbered as the preview numbers them**: the n-th match of the same
 * expression as `toggleCheckboxInContent` (`markedCheckboxes.js`), code
 * blocks included, so that ticking a task here ticks that very box.
 *
 * A due date is written the way Obsidian Tasks writes it, `📅 2026-10-12`,
 * anywhere on the line.
 */
final readonly class NoteTasks
{
    /** Mirrors `toggleCheckboxInContent` - see the class comment. */
    public const string CHECKBOX = '/^(\s*[-*+]\s+)\[([ xX])\][ \t]?(.*)$/m';

    private const int MAX_TASKS = 2000;

    public function __construct(private MarkdownNoteRepository $noteRepository) {}

    /**
     * @return list<array{noteId: int, noteTitle: string|null, noteIcon: string|null, noteLocked: bool, index: int, text: string, done: bool, due: string|null}>
     */
    public function forUser(CoreUserInterface $user): array
    {
        $tasks = [];
        foreach ($this->noteRepository->findAllWithContentForUser($user) as $note) {
            $content = (string) $note->getContent();
            if (!str_contains($content, '[')) {
                continue;
            }

            foreach (self::tasksIn($content) as $task) {
                $tasks[] = ['noteId' => (int) $note->getId(), 'noteTitle' => $note->getTitle(), 'noteIcon' => $note->getIcon(), 'noteLocked' => $note->isLocked(), ...$task];
                if (count($tasks) >= self::MAX_TASKS) {
                    return $tasks;
                }
            }
        }

        return $tasks;
    }

    /**
     * @return list<array{index: int, text: string, done: bool, due: string|null}>
     */
    public static function tasksIn(string $content): array
    {
        if (0 === preg_match_all(self::CHECKBOX, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $tasks = [];
        foreach ($matches as $index => $match) {
            $text = mb_trim($match[3]);
            $due = 1 === preg_match('/📅\s*(\d{4}-\d{2}-\d{2})/u', $text, $found) ? $found[1] : null;
            $tasks[] = [
                'index' => $index,
                'text' => mb_trim((string) preg_replace('/📅\s*\d{4}-\d{2}-\d{2}/u', '', $text)),
                'done' => 'x' === mb_strtolower($match[2]),
                'due' => $due,
            ];
        }

        return $tasks;
    }

    /**
     * The text with its n-th box ticked or unticked, or null when there is
     * no n-th box: the note changed since the list was read.
     */
    public static function withTask(string $content, int $index, bool $done): ?string
    {
        $position = -1;
        $found = false;
        $result = preg_replace_callback(self::CHECKBOX, static function (array $match) use (&$position, &$found, $index, $done): string {
            ++$position;
            if ($position !== $index) {
                return $match[0];
            }

            $found = true;

            return $match[1].'['.($done ? 'x' : ' ').']'.mb_substr($match[0], mb_strlen($match[1]) + 3);
        }, $content);

        return $found && is_string($result) ? $result : null;
    }
}
