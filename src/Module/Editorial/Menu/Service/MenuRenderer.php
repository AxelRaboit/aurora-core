<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Turns a stored menu into the tree a template renders.
 *
 * Entries hold what they point at, not a URL, so every render resolves
 * them. That is what keeps a renamed post's menu entry working - and what
 * makes batching the lookups matter, since navigation renders on every
 * page of the site.
 */
final class MenuRenderer
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $rendered = [];

    /** @var array<int, PostInterface|null> */
    private array $posts = [];

    /** @var array<int, TaxonomyTermInterface|null> */
    private array $terms = [];

    /** @var array<int, PostTypeInterface|null> */
    private array $postTypes = [];

    /**
     * Every menu, keyed by location, read once: a page renders three or four
     * of them, and one query per location was one query too many each time.
     *
     * @var array<string, MenuInterface>|null
     */
    private ?array $menus = null;

    public function __construct(
        private readonly MenuRepository $menuRepository,
        private readonly PostRepository $postRepository,
        private readonly TaxonomyTermRepository $termRepository,
        private readonly PostTypeRepository $postTypeRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
        private readonly SettingRepository $settingRepository,
        private readonly MenuActiveTrail $activeTrail,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function render(string $location, string $locale): array
    {
        $authenticated = $this->security->getUser() instanceof UserInterface;

        // A page can ask for the same location more than once - a header and
        // a mobile drawer, say - and who is looking changes the answer.
        $key = sprintf('%s|%s|%d', $location, $locale, (int) $authenticated);
        if (isset($this->rendered[$key])) {
            return $this->rendered[$key];
        }

        if (null === $this->menus) {
            $this->menus = [];
            foreach ($this->menuRepository->findAllWithItems() as $loaded) {
                $this->menus[$loaded->getLocation()] = $loaded;
            }
        }

        $menu = $this->menus[$location] ?? null;
        if (!$menu instanceof MenuInterface) {
            return $this->rendered[$key] = [];
        }

        // Children are read from the entries already loaded, not from each
        // entry's own collection: that one is lazy, and asking it cost a
        // query per entry on every page.
        $roots = [];
        $childrenByParent = [];
        foreach ($menu->getItems() as $item) {
            $parent = $item->getParent();
            if (null === $parent) {
                $roots[] = $item;
            } else {
                $childrenByParent[(int) $parent->getId()][] = $item;
            }
        }

        $this->prefetchTargets($menu->getItems());

        // Once for the whole tree: the address does not change while a
        // page renders, and a menu is dozens of entries deep.
        $currentPath = $this->activeTrail->currentPath();
        $currentSection = $this->activeTrail->currentPostTypeSlug();

        $tree = [];
        foreach ($roots as $item) {
            $resolved = $this->resolveItem($item, $childrenByParent, $locale, $authenticated, $currentPath, $currentSection);
            if (null !== $resolved) {
                $tree[] = $resolved;
            }
        }

        usort($tree, static fn (array $left, array $right): int => $left['_position'] <=> $right['_position']);
        $this->stripPositions($tree);

        return $this->rendered[$key] = $tree;
    }

    /**
     * @param array<int, list<MenuItemInterface>> $childrenByParent
     *
     * @return array<string, mixed>|null
     */
    private function resolveItem(MenuItemInterface $item, array $childrenByParent, string $locale, bool $authenticated, ?string $currentPath, ?string $currentSection = null): ?array
    {
        if (!$item->getVisibility()->isVisibleTo($authenticated)) {
            return null;
        }

        $label = $this->resolveLabel($item, $locale);
        if (null === $label || '' === $label) {
            return null;
        }

        $children = [];
        foreach ($childrenByParent[(int) $item->getId()] ?? [] as $child) {
            $resolved = $this->resolveItem($child, $childrenByParent, $locale, $authenticated, $currentPath, $currentSection);
            if (null !== $resolved) {
                $children[] = $resolved;
            }
        }

        usort($children, static fn (array $left, array $right): int => $left['_position'] <=> $right['_position']);
        $this->stripPositions($children);

        $url = $this->resolveUrl($item, $locale);

        // An entry that resolves to nothing is dropped - but only after its
        // children, and only if it has none. A label with children and no URL
        // is a heading, a footer column title say; dropping it here would take
        // the whole branch with it, since children are only reached through
        // their parent. Consumers get `url: null` and render it unclickable.
        if (null === $url && [] === $children) {
            return null;
        }

        $isCurrent = $this->activeTrail->isCurrent($currentPath, $url);

        return [
            '_position' => $item->getPosition(),
            'id' => $item->getId(),
            'label' => $label,
            'url' => $url,
            'targetType' => $item->getTargetType()->value,
            'openInNewTab' => $item->isOpenInNewTab(),
            'cssClass' => $item->getCssClass(),
            'children' => $children,
            // Exactly one entry can be the page, and it is what `aria-current`
            // names. Anything looser said "you are here" in three places at
            // once, which is worth less than saying nothing.
            'isCurrent' => $isCurrent,
            // The branch the page belongs to - what the highlight follows, so
            // "Projets" stays lit while the reader is inside a project. A
            // parent inherits it from its children: a dropdown whose open page
            // is one of its entries is itself part of the trail.
            'isActive' => $isCurrent
                || $this->isAncestor($item, $currentPath, $url)
                || $this->headsCurrentSection($item, $currentSection)
                || $this->hasActiveChild($children),
        ];
    }

    /**
     * The home link is above every address on the site, so letting it answer
     * as an ancestor would light it on every page and tell the reader
     * nothing. It is current when it is the page, and otherwise quiet.
     */
    private function isAncestor(MenuItemInterface $item, ?string $currentPath, ?string $url): bool
    {
        if (MenuItemTargetTypeEnum::Home === $item->getTargetType()) {
            return false;
        }

        return $this->activeTrail->isAncestorOf($currentPath, $url);
    }

    /**
     * The page being looked at belongs to the type this entry heads.
     *
     * The one relation the addresses cannot express: a hub page and the
     * publications it introduces can live in different branches of the site,
     * and an entry pointing at the hub went dark on every one of them.
     */
    private function headsCurrentSection(MenuItemInterface $item, ?string $currentSection): bool
    {
        $typeId = $item->getSectionPostTypeId();

        if (null === $typeId || null === $currentSection) {
            return false;
        }

        return $this->postType($typeId)?->getSlug() === $currentSection;
    }

    /** @param array<int, array<string, mixed>> $children */
    private function hasActiveChild(array $children): bool
    {
        return array_any($children, fn ($child): bool => true === ($child['isActive'] ?? false));
    }

    /**
     * The entry's own label wins; left empty it borrows the target's title,
     * so a menu of posts follows their translations untouched.
     */
    private function resolveLabel(MenuItemInterface $item, string $locale): ?string
    {
        $label = $item->getTranslation($locale)?->getLabel();
        if (null !== $label && '' !== $label) {
            return $label;
        }

        return match ($item->getTargetType()) {
            MenuItemTargetTypeEnum::Post => $this->post($item->getTargetId())?->getTranslation($locale)?->getTitle(),
            MenuItemTargetTypeEnum::Term => $this->term($item->getTargetId())?->getTranslation($locale)?->getName(),
            MenuItemTargetTypeEnum::PostTypeArchive => $this->postType($item->getTargetId())?->getLabel(),
            default => null,
        };
    }

    private function resolveUrl(MenuItemInterface $item, string $locale): ?string
    {
        // An install can turn front-end accounts off entirely, for a site that
        // is pure brochure. Reading the parameter beats asking the Auth module:
        // navigation stays free of a dependency on a front that may not be
        // installed at all - the same reason the lookups below tolerate a
        // missing route. Resolving to null drops the entry, so the sign-in
        // link leaves the header without anyone editing the menu.
        if ($item->getTargetType()->isAccountLink() && !$this->frontAccountsEnabled()) {
            return null;
        }

        return match ($item->getTargetType()) {
            MenuItemTargetTypeEnum::Home => $this->route('editorial_home', ['locale' => $locale]),
            MenuItemTargetTypeEnum::CustomUrl => $item->getCustomUrl(),
            MenuItemTargetTypeEnum::Post => $this->postUrl($item, $locale),
            MenuItemTargetTypeEnum::Term => $this->termUrl($item, $locale),
            MenuItemTargetTypeEnum::PostTypeArchive => $this->archiveUrl($item, $locale),
            // Account links belong to whichever front owns sign-in. It may
            // not be installed, so a missing route means "no such link here"
            // rather than a crashed navigation on every page.
            MenuItemTargetTypeEnum::FrontLogin => $this->route('frontend_login', ['locale' => $locale]),
            MenuItemTargetTypeEnum::FrontRegister => $this->route('frontend_register', ['locale' => $locale]),
            MenuItemTargetTypeEnum::FrontAccount => $this->route('frontend_account', ['locale' => $locale]),
            MenuItemTargetTypeEnum::FrontLogout => $this->route('frontend_logout', ['locale' => $locale]),
        };
    }

    private function postUrl(MenuItemInterface $item, string $locale): ?string
    {
        $post = $this->post($item->getTargetId());
        if (!$post instanceof PostInterface || !$post->isOnSite()) {
            return null;
        }

        $slug = $post->getTranslation($locale)?->getSlug();
        if (null === $slug || '' === $slug) {
            return null;
        }

        return $this->route('editorial_post', [
            'locale' => $locale,
            'postTypeSlug' => $post->getPostType()->getSlug(),
            'slug' => $slug,
        ]);
    }

    private function termUrl(MenuItemInterface $item, string $locale): ?string
    {
        $term = $this->term($item->getTargetId());
        $slug = $term?->getTranslation($locale)?->getSlug();
        if (!$term instanceof TaxonomyTermInterface || null === $slug || '' === $slug) {
            return null;
        }

        return $this->route('editorial_term', [
            'locale' => $locale,
            'taxonomySlug' => $term->getTaxonomy()->getSlug(),
            'termSlug' => $slug,
        ]);
    }

    private function archiveUrl(MenuItemInterface $item, string $locale): ?string
    {
        $postType = $this->postType($item->getTargetId());
        if (!$postType instanceof PostTypeInterface || !$postType->hasArchive()) {
            return null;
        }

        return $this->route('editorial_archive', [
            'locale' => $locale,
            'postTypeSlug' => $postType->getSlug(),
        ]);
    }

    /**
     * Defaults to enabled so a site whose settings row predates the parameter
     * keeps the account links it already renders. SettingRepository warms its
     * own per-request cache, so asking once per entry costs an array lookup.
     */
    private function frontAccountsEnabled(): bool
    {
        return $this->settingRepository->getBoolean(
            ApplicationParameterEnum::FrontLoginEnabled->value,
            true,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function route(string $name, array $parameters): ?string
    {
        try {
            return $this->urlGenerator->generate($name, $parameters);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * One query per target type for the whole tree, rather than one per
     * entry. Navigation is on every page; this is the difference between
     * three queries and thirty.
     *
     * @param iterable<MenuItemInterface> $items
     */
    private function prefetchTargets(iterable $items): void
    {
        /** @var array<string, list<int>> $ids */
        $ids = [];
        foreach ($items as $item) {
            $targetId = $item->getTargetId();
            if (null !== $targetId) {
                $ids[$item->getTargetType()->value][] = $targetId;
            }

            // A section entry names a post type too, read on every page to
            // decide which entry is current.
            $sectionTypeId = $item->getSectionPostTypeId();
            if (null !== $sectionTypeId) {
                $ids[MenuItemTargetTypeEnum::PostTypeArchive->value][] = $sectionTypeId;
            }
        }

        $missing = $this->missing($ids[MenuItemTargetTypeEnum::Post->value] ?? [], $this->posts);
        if ([] !== $missing) {
            foreach ($this->postRepository->findForDisplay($missing) as $post) {
                $this->posts[(int) $post->getId()] = $post;
            }

            $this->rememberMisses($missing, $this->posts);
        }

        $missing = $this->missing($ids[MenuItemTargetTypeEnum::Term->value] ?? [], $this->terms);
        if ([] !== $missing) {
            foreach ($this->termRepository->findForDisplay($missing) as $term) {
                $this->terms[(int) $term->getId()] = $term;
            }

            $this->rememberMisses($missing, $this->terms);
        }

        $missing = $this->missing($ids[MenuItemTargetTypeEnum::PostTypeArchive->value] ?? [], $this->postTypes);
        if ([] !== $missing) {
            foreach ($this->postTypeRepository->findBy(['id' => $missing]) as $postType) {
                $this->postTypes[(int) $postType->getId()] = $postType;
            }

            $this->rememberMisses($missing, $this->postTypes);
        }
    }

    /**
     * @param list<int>               $ids
     * @param array<int, object|null> $cache
     *
     * @return list<int>
     */
    private function missing(array $ids, array $cache): array
    {
        return array_values(array_filter(
            array_unique($ids),
            static fn (int $id): bool => !array_key_exists($id, $cache),
        ));
    }

    /**
     * Misses are cached too, so a deleted target is not looked up again on
     * every entry that still points at it. Read back with `array_key_exists`,
     * never `??=`, which takes a remembered null for a key never seen.
     *
     * @param list<int>               $ids
     * @param array<int, object|null> $cache
     */
    private function rememberMisses(array $ids, array &$cache): void
    {
        foreach ($ids as $id) {
            $cache[$id] ??= null;
        }
    }

    private function post(?int $id): ?PostInterface
    {
        if (null === $id) {
            return null;
        }

        if (!array_key_exists($id, $this->posts)) {
            $this->posts[$id] = $this->postRepository->find($id);
        }

        return $this->posts[$id];
    }

    private function term(?int $id): ?TaxonomyTermInterface
    {
        if (null === $id) {
            return null;
        }

        if (!array_key_exists($id, $this->terms)) {
            $this->terms[$id] = $this->termRepository->find($id);
        }

        return $this->terms[$id];
    }

    private function postType(?int $id): ?PostTypeInterface
    {
        if (null === $id) {
            return null;
        }

        if (!array_key_exists($id, $this->postTypes)) {
            $this->postTypes[$id] = $this->postTypeRepository->find($id);
        }

        return $this->postTypes[$id];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function stripPositions(array &$items): void
    {
        foreach ($items as &$item) {
            unset($item['_position']);
        }
    }
}
