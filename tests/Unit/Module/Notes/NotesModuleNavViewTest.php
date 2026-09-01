<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\NotesModule;
use PHPUnit\Framework\TestCase;

/**
 * Notes was the one module whose view had no test at all - its panel and its
 * page are covered on the JavaScript side, but nothing here asserted that the
 * module declares a view in the first place, or that it stops declaring one
 * when the module is switched off.
 */
final class NotesModuleNavViewTest extends TestCase
{
    private function makeModule(bool $backend = true, bool $markdown = true): NotesModule
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(
            static fn (ModuleParameterEnum $param): bool => match ($param) {
                ModuleParameterEnum::NotesBackend => $backend,
                ModuleParameterEnum::NotesMarkdown => $markdown,
                default => false,
            },
        );

        return new NotesModule(new NotesContext($checker));
    }

    public function testItDeclaresTheTreePanel(): void
    {
        $view = $this->makeModule()->getModuleNavView();

        self::assertNotNull($view);
        self::assertSame('notes', $view->moduleId);
        self::assertSame('notes/backend/markdown/NoteTreePanel', $view->panelComponent);
    }

    /**
     * One destination, and it is the row that brought the reader here. The
     * panel is what the view is for.
     */
    public function testItListsTheOneDestinationItHas(): void
    {
        $view = $this->makeModule()->getModuleNavView();

        self::assertCount(1, $view->groups);
        self::assertCount(1, $view->groups[0]->items);
        self::assertSame('backend_notes_markdown', $view->groups[0]->items[0]->route);
    }

    public function testItDeclaresNothingWhenTheModuleIsOff(): void
    {
        self::assertNull($this->makeModule(backend: false)->getModuleNavView());
        self::assertNull($this->makeModule(markdown: false)->getModuleNavView());
    }
}
