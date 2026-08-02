<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Editorial — the content module: post types, taxonomies, posts, menus.
 *
 * Being rebuilt one sub-domain at a time: a screen appears here in the
 * same commit that brings its controller and its toggle, never before.
 */
final readonly class EditorialModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private EditorialContext $editorialContext) {}

    public function getId(): string
    {
        return 'editorial';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('editorial.post_types.view'),
            new NavPermission('editorial.post_types.create'),
            new NavPermission('editorial.post_types.edit'),
            new NavPermission('editorial.post_types.delete'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->editorialContext->isBackendEnabled()) {
            return [];
        }

        $items = [];

        if ($this->editorialContext->isPostTypesEnabled()) {
            $items[] = $this->postTypesNavItem();
        }

        if ([] === $items) {
            return [];
        }

        return [new NavSection('editorial', $items, priority: 30)];
    }

    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('editorial', [
                $this->postTypesNavItem(),
            ], priority: 30),
        ];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::EditorialBackend->toToggle(),
            ModuleParameterEnum::EditorialPostTypes->toToggle(),
        ];
    }

    private function postTypesNavItem(): NavItem
    {
        return new NavItem(
            'backend_editorial_post_types',
            'backend.nav.post_types',
            'layout-template',
            requiredPrivilege: 'editorial.post_types.view',
            descriptionKey: 'backend.nav.post_types_description',
        );
    }
}
