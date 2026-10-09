<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes;

use Aurora\Module\Notes\Markdown\Service\NoteTasks;
use PHPUnit\Framework\TestCase;

/** The checkboxes of a note, for the tasks view (09/10/2026). */
final class NoteTasksTest extends TestCase
{
    public function testReadsEveryBoxWithItsStateAndDueDate(): void
    {
        $tasks = NoteTasks::tasksIn("# Courses\n- [ ] Pain 📅 2026-10-12\n* [x] Lait\n  + [ ] Œufs\nTexte - [ ] pas une tâche");

        self::assertSame([
            ['index' => 0, 'text' => 'Pain', 'done' => false, 'due' => '2026-10-12'],
            ['index' => 1, 'text' => 'Lait', 'done' => true, 'due' => null],
            ['index' => 2, 'text' => 'Œufs', 'done' => false, 'due' => null],
        ], $tasks);
    }

    public function testTicksTheBoxAtItsIndexOnly(): void
    {
        $content = "- [ ] Un\n- [ ] Deux 📅 2026-10-12\n- [x] Trois";

        self::assertSame("- [ ] Un\n- [x] Deux 📅 2026-10-12\n- [x] Trois", NoteTasks::withTask($content, 1, true));
        self::assertSame("- [ ] Un\n- [ ] Deux 📅 2026-10-12\n- [ ] Trois", NoteTasks::withTask($content, 2, false));
    }

    public function testAnIndexPastTheLastBoxChangesNothing(): void
    {
        self::assertNull(NoteTasks::withTask('- [ ] Seule', 3, true));
    }
}
