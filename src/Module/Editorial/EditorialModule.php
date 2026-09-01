<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\ModuleNavView;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Repository\FormRepository;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Editorial - the content module: post types, taxonomies, posts, menus.
 *
 * Being rebuilt one sub-domain at a time: a screen appears here in the
 * same commit that brings the Vue app behind it, never before - a menu
 * item leading to a blank page is worse than no menu item.
 */
final readonly class EditorialModule implements ModuleInterface, ModuleNavViewProviderInterface, ModuleToggleProviderInterface
{
    public function __construct(
        private EditorialContext $editorialContext,
        private PostTypeRepository $postTypeRepository,
        private TaxonomyRepository $taxonomyRepository,
        private MenuRepository $menuRepository,
        private FormRepository $formRepository,
        private TranslatorInterface $translator,
    ) {}

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
            new NavPermission('editorial.posts.publish'),
            new NavPermission('editorial.posts.manage'),
            new NavPermission('editorial.posts.gallery'),
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
            new NavPermission('editorial.forms.view'),
            new NavPermission('editorial.forms.create'),
            new NavPermission('editorial.forms.edit'),
            new NavPermission('editorial.forms.delete'),
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
            // Gated by the same toggle: a gallery belongs to a publication, so
            // turning publications off has to take this with it rather than leave
            // an entry that lists nothing.
            $items[] = $this->postGalleriesNavItem();
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

        if ($this->editorialContext->isFormsEnabled()) {
            $items[] = $this->formsNavItem();
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
                $this->formsNavItem(),
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
            ModuleParameterEnum::EditorialForms->toToggle(),
        ];
    }

    /**
     * The menu the reader gets while they are inside Editorial.
     *
     * The destinations first, then one group per family whose records are
     * themselves destinations. A post type is a page now - it has an address,
     * so it can be linked, bookmarked and found by the palette - and the column
     * of buttons that used to pick one, inside the page, is gone.
     *
     * The generic "post types" row is deliberately absent from the group: a
     * bare `/post-types` redirects to the first one, so a row for it and a row
     * for the first type would be two entries pointing at one screen. The
     * group's header carries the family name instead, exactly as the settings
     * group does. The project menu keeps its row, which is the way *in*.
     *
     * Each entry carries the second line the picker used to show under the
     * name - a slug, a menu's location. With "show descriptions" on, a row
     * without one sits blank next to rows that have one, and the reader has
     * lost the very thing that told two records apart.
     *
     * This is the one implementation that queries. `ModuleNavResolver` only
     * asks the module that owns the current route, so the cost lands on
     * Editorial's own pages and nowhere else - the same trade `Configuration`
     * documents for its contributed tabs.
     */
    public function getModuleNavView(): ?ModuleNavView
    {
        if (!$this->editorialContext->isBackendEnabled()) {
            return null;
        }

        $groups = [];
        $destinations = $this->enabledDestinations();

        if ([] !== $destinations) {
            $groups[] = new ModuleNavGroup('destinations', $destinations);
        }

        if ($this->editorialContext->isTaxonomiesEnabled()) {
            $items = [];

            foreach ($this->taxonomyRepository->findAllForIndex() as $taxonomy) {
                $items[] = new NavItem(
                    route: 'backend_editorial_taxonomies_show',
                    labelKey: 'backend.nav.taxonomies',
                    icon: 'tags',
                    requiredPrivilege: 'editorial.taxonomies.view',
                    routeParams: ['id' => $taxonomy->getId()],
                    key: sprintf('editorial.taxonomy.%d', $taxonomy->getId()),
                    label: $this->taxonomyLabel($taxonomy),
                    description: $this->taxonomyDescription($taxonomy),
                );
            }

            if ([] !== $items) {
                $groups[] = new ModuleNavGroup('taxonomies', $items, labelKey: 'backend.nav.taxonomies');
            }
        }

        if ($this->editorialContext->isMenusEnabled()) {
            $items = [];

            foreach ($this->menuRepository->findAllWithItems() as $menu) {
                $items[] = new NavItem(
                    route: 'backend_editorial_menus_show',
                    labelKey: 'backend.nav.menus',
                    icon: 'menu',
                    requiredPrivilege: 'editorial.menus.view',
                    routeParams: ['id' => $menu->getId()],
                    key: sprintf('editorial.menu.%d', $menu->getId()),
                    label: $menu->getName(),
                    description: $menu->getDescription() ?? '',
                );
            }

            if ([] !== $items) {
                $groups[] = new ModuleNavGroup('menus', $items, labelKey: 'backend.nav.menus');
            }
        }

        if ($this->editorialContext->isFormsEnabled()) {
            $items = [];

            foreach ($this->formRepository->findAllForIndex() as $form) {
                $items[] = new NavItem(
                    route: 'backend_editorial_forms_show',
                    labelKey: 'backend.nav.forms',
                    icon: 'clipboard-list',
                    requiredPrivilege: 'editorial.forms.view',
                    routeParams: ['id' => $form->getId()],
                    key: sprintf('editorial.form.%d', $form->getId()),
                    label: $this->formTitle($form),
                    description: $this->formDescription($form),
                );
            }

            if ([] !== $items) {
                $groups[] = new ModuleNavGroup('forms', $items, labelKey: 'backend.nav.forms');
            }
        }

        if ($this->editorialContext->isPostTypesEnabled()) {
            $items = [];

            foreach ($this->postTypeRepository->findAllWithRelations() as $postType) {
                $items[] = new NavItem(
                    route: 'backend_editorial_post_types_show',
                    labelKey: 'backend.nav.post_types',
                    icon: 'layout-template',
                    requiredPrivilege: 'editorial.post_types.view',
                    routeParams: ['id' => $postType->getId()],
                    // Every entry shares one route name, so the route name
                    // cannot identify them: without a key of its own, hiding
                    // one post type from the menu would hide all of them, and
                    // the active row would be all of them at once.
                    key: sprintf('editorial.post_type.%d', $postType->getId()),
                    label: $postType->getLabel(),
                    description: $this->postTypeDescription($postType),
                );
            }

            if ([] !== $items) {
                $groups[] = new ModuleNavGroup('post_types', $items, labelKey: 'backend.nav.post_types');
            }
        }

        return [] === $groups ? null : new ModuleNavView('editorial', $groups);
    }

    /**
     * The destinations whose toggle is on, minus the families the view lists
     * record by record - their entry point is the group header below.
     *
     * @return NavItem[]
     */
    private function enabledDestinations(): array
    {
        $items = [];

        if ($this->editorialContext->isPostsEnabled()) {
            $items[] = $this->postsNavItem();
            $items[] = $this->postGalleriesNavItem();
        }

        if ($this->editorialContext->isCommentsEnabled()) {
            $items[] = $this->commentsNavItem();
        }

        return $items;
    }

    /**
     * What each entry says under its name.
     *
     * The record's own description, when it has one - the same sentence the
     * page shows. It was the slug at first, which reads as a technical token
     * beside "Modérer les commentaires des lecteurs" on the row above: a second
     * line has to be worth the space it takes.
     *
     * The count is the fallback, not the rule: every one of the four has a
     * description field now, and a record whose author left it blank says what
     * it holds instead. A fact is not a sentence, but it beats a blank line
     * under a name.
     */
    private function taxonomyDescription(TaxonomyInterface $taxonomy): string
    {
        $translation = $taxonomy->getTranslation($this->translator->getLocale())
            ?? ($taxonomy->getTranslations()->first() ?: null);

        return $translation?->getDescription()
            ?? $this->translator->trans('backend.nav.counts.terms', ['%count%' => $taxonomy->getTerms()->count()]);
    }

    private function formDescription(FormInterface $form): string
    {
        $translation = $form->getTranslation($this->translator->getLocale())
            ?? ($form->getTranslations()->first() ?: null);

        return $translation?->getDescription()
            ?? $this->translator->trans('backend.nav.counts.fields', ['%count%' => $form->getFields()->count()]);
    }

    private function postTypeDescription(PostTypeInterface $postType): string
    {
        return $postType->getDescription()
            ?? $this->translator->trans('backend.nav.counts.posts', ['%count%' => $postType->getPosts()->count()]);
    }

    /**
     * A form's title in the reader's language, same shape as a taxonomy's: the
     * title lives on a translation row, so there is no locale-free name, and an
     * entry with no name is worse than one named after its reference.
     */
    private function formTitle(FormInterface $form): string
    {
        $translation = $form->getTranslation($this->translator->getLocale())
            ?? ($form->getTranslations()->first() ?: null);

        return $translation?->getTitle() ?? ($form->getReference() ?? '#'.$form->getId());
    }

    /**
     * A taxonomy's name in the reader's language, falling back to whatever
     * translation exists and then to the slug.
     *
     * The label lives on a translation row, so there is no locale-free name to
     * show - and an entry with no name is worse than one named after its slug.
     * Same shape as `TaxonomyTermSerializer` uses for the same problem.
     */
    private function taxonomyLabel(TaxonomyInterface $taxonomy): string
    {
        $translation = $taxonomy->getTranslation($this->translator->getLocale())
            ?? ($taxonomy->getTranslations()->first() ?: null);

        return $translation?->getLabel() ?? $taxonomy->getSlug();
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

    /**
     * A second way into the same publications, stopping at their galleries.
     *
     * Its own entry rather than a mode of the one above, because it answers a
     * different question - "which publications can I add photos to" - and because
     * somebody whose whole job is the pictures should not have to learn that the
     * way in is a tab three clicks deep.
     *
     * `editorial.posts.gallery` is deliberately not implied by
     * `editorial.posts.edit`: the point of the privilege is to be grantable *on
     * its own*, to a contributor who must not be able to publish, retitle or
     * refile anything.
     */
    private function postGalleriesNavItem(): NavItem
    {
        return new NavItem(
            'backend_editorial_post_galleries',
            'backend.nav.post_galleries',
            'images',
            requiredPrivilege: 'editorial.posts.gallery',
            descriptionKey: 'backend.nav.post_galleries_description',
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

    private function formsNavItem(): NavItem
    {
        return new NavItem(
            'backend_editorial_forms',
            'backend.nav.forms',
            'clipboard-list',
            requiredPrivilege: 'editorial.forms.view',
            descriptionKey: 'backend.nav.forms_description',
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
