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

    public function __construct(
        private readonly MenuRepository $menuRepository,
        private readonly PostRepository $postRepository,
        private readonly TaxonomyTermRepository $termRepository,
        private readonly PostTypeRepository $postTypeRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
        private readonly SettingRepository $settingRepository,
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

        $menu = $this->menuRepository->findOneByLocation($location);
        if (!$menu instanceof MenuInterface) {
            return $this->rendered[$key] = [];
        }

        $roots = [];
        foreach ($menu->getItems() as $item) {
            if (null === $item->getParent()) {
                $roots[] = $item;
            }
        }

        $this->prefetchTargets($menu->getItems());

        $tree = [];
        foreach ($roots as $item) {
            $resolved = $this->resolveItem($item, $locale, $authenticated);
            if (null !== $resolved) {
                $tree[] = $resolved;
            }
        }

        usort($tree, static fn (array $a, array $b): int => $a['_position'] <=> $b['_position']);
        $this->stripPositions($tree);

        return $this->rendered[$key] = $tree;
    }

    /** @return array<string, mixed>|null */
    private function resolveItem(MenuItemInterface $item, string $locale, bool $authenticated): ?array
    {
        if (!$item->getVisibility()->isVisibleTo($authenticated)) {
            return null;
        }

        $label = $this->resolveLabel($item, $locale);
        if (null === $label || '' === $label) {
            return null;
        }

        $children = [];
        foreach ($item->getChildren() as $child) {
            $resolved = $this->resolveItem($child, $locale, $authenticated);
            if (null !== $resolved) {
                $children[] = $resolved;
            }
        }

        usort($children, static fn (array $a, array $b): int => $a['_position'] <=> $b['_position']);
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

        return [
            '_position' => $item->getPosition(),
            'id' => $item->getId(),
            'label' => $label,
            'url' => $url,
            'targetType' => $item->getTargetType()->value,
            'openInNewTab' => $item->isOpenInNewTab(),
            'cssClass' => $item->getCssClass(),
            'children' => $children,
        ];
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
        if (!$post instanceof PostInterface || $post->isTrashed() || !$post->isPublished()) {
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
        }

        $missing = $this->missing($ids[MenuItemTargetTypeEnum::Post->value] ?? [], $this->posts);
        if ([] !== $missing) {
            foreach ($this->postRepository->findBy(['id' => $missing]) as $post) {
                $this->posts[(int) $post->getId()] = $post;
            }

            $this->rememberMisses($missing, $this->posts);
        }

        $missing = $this->missing($ids[MenuItemTargetTypeEnum::Term->value] ?? [], $this->terms);
        if ([] !== $missing) {
            foreach ($this->termRepository->findBy(['id' => $missing]) as $term) {
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
     * every entry that still points at it.
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

        return $this->posts[$id] ??= $this->postRepository->find($id);
    }

    private function term(?int $id): ?TaxonomyTermInterface
    {
        if (null === $id) {
            return null;
        }

        return $this->terms[$id] ??= $this->termRepository->find($id);
    }

    private function postType(?int $id): ?PostTypeInterface
    {
        if (null === $id) {
            return null;
        }

        return $this->postTypes[$id] ??= $this->postTypeRepository->find($id);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function stripPositions(array &$items): void
    {
        foreach ($items as &$item) {
            unset($item['_position']);
        }
    }
}
