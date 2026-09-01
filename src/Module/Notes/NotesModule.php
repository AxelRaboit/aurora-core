<?php

declare(strict_types=1);

namespace Aurora\Module\Notes;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\ModuleNavView;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

final readonly class NotesModule implements ModuleInterface, ModuleNavViewProviderInterface, ModuleToggleProviderInterface
{
    public function __construct(private NotesContext $notesContext) {}

    public function getId(): string
    {
        return 'notes';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('notes.markdown.use'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->notesContext->isBackendEnabled()) {
            return [];
        }

        $items = [];

        if ($this->notesContext->isMarkdownEnabled()) {
            $items[] = $this->markdownNavItem();
        }

        if ([] === $items) {
            return [];
        }

        return [new NavSection('notes', $items, priority: 40)];
    }

    public function getCatalogNavSections(): array
    {
        return [new NavSection('notes', [$this->markdownNavItem()], priority: 40)];
    }

    /**
     * The notes themselves, in the menu, through the panel.
     *
     * The widest of the asides the module system set out to move, and the one
     * `panelComponent` exists for: nine hundred notes cannot be nine hundred
     * `NavItem`s the way a handful of post types can, and what the reader needs
     * on top of the list - a search field, a tag filter, a fold state - is not
     * something a list of links expresses.
     *
     * No entries of our own: the module has exactly one destination, and it is
     * already the row that brought the reader here. The panel is the whole
     * view.
     */
    public function getModuleNavView(): ?ModuleNavView
    {
        if (!$this->notesContext->isBackendEnabled() || !$this->notesContext->isMarkdownEnabled()) {
            return null;
        }

        return new ModuleNavView(
            'notes',
            [new ModuleNavGroup('destinations', [$this->markdownNavItem()])],
            panelComponent: 'notes/backend/markdown/NoteTreePanel',
        );
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::NotesBackend->toToggle(),
            ModuleParameterEnum::NotesMarkdown->toToggle(),
        ];
    }

    private function markdownNavItem(): NavItem
    {
        return new NavItem(
            'backend_notes_markdown',
            'backend.nav.notes_markdown',
            'notebook-pen',
            requiredPrivilege: 'notes.markdown.use',
            descriptionKey: 'backend.nav.notes_markdown_description',
        );
    }
}
