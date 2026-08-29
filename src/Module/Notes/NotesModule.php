<?php

declare(strict_types=1);

namespace Aurora\Module\Notes;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

final readonly class NotesModule implements ModuleInterface, ModuleToggleProviderInterface
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
