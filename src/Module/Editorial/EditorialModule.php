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
 * same commit that brings the Vue app behind it, never before — a menu
 * item leading to a blank page is worse than no menu item.
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
            new NavPermission('editorial.posts.view'),
            new NavPermission('editorial.posts.create'),
            new NavPermission('editorial.posts.edit'),
            new NavPermission('editorial.posts.delete'),
            new NavPermission('editorial.posts.manage'),
            new NavPermission('editorial.post_types.view'),
            new NavPermission('editorial.post_types.create'),
            new NavPermission('editorial.post_types.edit'),
            new NavPermission('editorial.post_types.delete'),
            new NavPermission('editorial.taxonomies.view'),
            new NavPermission('editorial.taxonomies.create'),
            new NavPermission('editorial.taxonomies.edit'),
            new NavPermission('editorial.taxonomies.delete'),
            new NavPermission('editorial.menus.view'),
            new NavPermission('editorial.menus.edit'),
            new NavPermission('editorial.comments.view'),
            new NavPermission('editorial.comments.moderate'),
            new NavPermission('editorial.comments.delete'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->editorialContext->isBackendEnabled()) {
            return [];
        }

        $items = [];

        if ($this->editorialContext->isPostsEnabled()) {
            $items[] = $this->postsNavItem();
        }

        if ($this->editorialContext->isPostTypesEnabled()) {
            $items[] = $this->postTypesNavItem();
        }

        if ($this->editorialContext->isTaxonomiesEnabled()) {
            $items[] = $this->taxonomiesNavItem();
        }

        if ($this->editorialContext->isMenusEnabled()) {
            $items[] = $this->menusNavItem();
        }

        if ($this->editorialContext->isCommentsEnabled()) {
            $items[] = $this->commentsNavItem();
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
                $this->postsNavItem(),
                $this->postTypesNavItem(),
                $this->taxonomiesNavItem(),
                $this->menusNavItem(),
                $this->commentsNavItem(),
            ], priority: 30),
        ];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::EditorialBackend->toToggle(),
            ModuleParameterEnum::EditorialPosts->toToggle(),
            ModuleParameterEnum::EditorialPostTypes->toToggle(),
            ModuleParameterEnum::EditorialTaxonomies->toToggle(),
            ModuleParameterEnum::EditorialMenus->toToggle(),
            ModuleParameterEnum::EditorialSeo->toToggle(),
            ModuleParameterEnum::EditorialComments->toToggle(),
        ];
    }

    private function postsNavItem(): NavItem
    {
        return new NavItem(
            'backend_editorial_posts',
            'backend.nav.posts',
            'file-text',
            requiredPrivilege: 'editorial.posts.view',
            descriptionKey: 'backend.nav.posts_description',
        );
    }

    private function taxonomiesNavItem(): NavItem
    {
        return new NavItem(
            'backend_editorial_taxonomies',
            'backend.nav.taxonomies',
            'tags',
            requiredPrivilege: 'editorial.taxonomies.view',
            descriptionKey: 'backend.nav.taxonomies_description',
        );
    }

    private function commentsNavItem(): NavItem
    {
        return new NavItem(
            'backend_editorial_comments',
            'backend.nav.comments',
            'message-square',
            requiredPrivilege: 'editorial.comments.view',
            descriptionKey: 'backend.nav.comments_description',
        );
    }

    private function menusNavItem(): NavItem
    {
        return new NavItem(
            'backend_editorial_menus',
            'backend.nav.menus',
            'menu',
            requiredPrivilege: 'editorial.menus.view',
            descriptionKey: 'backend.nav.menus_description',
        );
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
