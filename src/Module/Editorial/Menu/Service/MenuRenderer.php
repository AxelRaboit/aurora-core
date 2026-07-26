<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Service;

use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves menus to a tree of ready-to-render items:
 *  - Each MenuItem's target_type is resolved into a concrete URL
 *  - Each label falls back to the target's natural label if no translation override
 *  - Items not visible to the current user (guests_only / authenticated_only) are filtered out
 *  - Items whose target no longer exists are silently dropped (so a deleted post doesn't 500 the menu)
 *
 * Results are memoised per-request by (location, locale, isAuthenticated).
 */
final class MenuRenderer
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $cache = [];

    /** @var array<string, MenuInterface>|null */
    private ?array $menusByLocation = null;

    public function __construct(
        private readonly MenuRepository $menuRepository,
        private readonly PostRepository $postRepository,
        private readonly TaxonomyTermRepository $termRepository,
        private readonly PostTypeRepository $postTypeRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @return array<int, array<string, mixed>> tree of items, each item has shape:
     *                                          {id, label, url, openInNewTab, cssClass, children}
     */
    public function render(string $location, string $locale): array
    {
        $isAuthenticated = $this->security->getUser() instanceof UserInterface;
        $cacheKey = $location.'|'.$locale.'|'.($isAuthenticated ? '1' : '0');

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $this->menusByLocation ??= $this->menuRepository->findAllWithItemsKeyedByLocation();
        $menu = $this->menusByLocation[$location] ?? null;
        if (!$menu instanceof MenuInterface) {
            return $this->cache[$cacheKey] = [];
        }

        $rootItems = $menu->getItems()->filter(static fn (MenuItemInterface $item): bool => !$item->getParent() instanceof MenuItemInterface);
        $rendered = [];
        foreach ($rootItems as $item) {
            $resolved = $this->resolveItem($item, $locale, $isAuthenticated);
            if (null !== $resolved) {
                $rendered[] = $resolved;
            }
        }

        usort($rendered, static fn (array $a, array $b): int => $a['_position'] <=> $b['_position']);
        $this->stripPositions($rendered);

        return $this->cache[$cacheKey] = $rendered;
    }

    /** @return array<string, mixed>|null */
    private function resolveItem(MenuItemInterface $item, string $locale, bool $isAuthenticated): ?array
    {
        if (!$this->isVisible($item, $isAuthenticated)) {
            return null;
        }

        $url = $this->resolveUrl($item, $locale);
        if (null === $url) {
            return null;
        }

        $label = $this->resolveLabel($item, $locale);
        if (null === $label || '' === $label) {
            return null;
        }

        $children = [];
        foreach ($item->getChildren() as $child) {
            $resolved = $this->resolveItem($child, $locale, $isAuthenticated);
            if (null !== $resolved) {
                $children[] = $resolved;
            }
        }

        usort($children, static fn (array $a, array $b): int => $a['_position'] <=> $b['_position']);
        $this->stripPositions($children);

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

    private function isVisible(MenuItemInterface $item, bool $isAuthenticated): bool
    {
        return match ($item->getVisibility()) {
            MenuItemVisibilityEnum::Always => true,
            MenuItemVisibilityEnum::GuestsOnly => !$isAuthenticated,
            MenuItemVisibilityEnum::AuthenticatedOnly => $isAuthenticated,
        };
    }

    private function resolveUrl(MenuItemInterface $item, string $locale): ?string
    {
        return match ($item->getTargetType()) {
            MenuItemTargetTypeEnum::Home => $this->urlGenerator->generate('editorial_home', ['locale' => $locale]),
            MenuItemTargetTypeEnum::FrontLogin => $this->urlGenerator->generate('frontend_login', ['locale' => $locale]),
            MenuItemTargetTypeEnum::FrontRegister => $this->urlGenerator->generate('frontend_register', ['locale' => $locale]),
            MenuItemTargetTypeEnum::FrontAccount => $this->urlGenerator->generate('frontend_account', ['locale' => $locale]),
            MenuItemTargetTypeEnum::FrontLogout => $this->urlGenerator->generate('frontend_logout', ['locale' => $locale]),
            MenuItemTargetTypeEnum::CustomUrl => $item->getCustomUrl() ?: null,
            MenuItemTargetTypeEnum::Post => $this->resolvePostUrl($item, $locale),
            MenuItemTargetTypeEnum::Term => $this->resolveTermUrl($item, $locale),
            MenuItemTargetTypeEnum::PostTypeArchive => $this->resolveArchiveUrl($item, $locale),
        };
    }

    private function resolvePostUrl(MenuItemInterface $item, string $locale): ?string
    {
        $post = $this->postRepository->find($item->getTargetId());
        if (!$post instanceof PostInterface || $post->isTrashed() || !$post->isPublished()) {
            return null;
        }

        $translation = $post->getTranslation($locale) ?? $post->getTranslations()->first();
        if (!$translation || !$translation->getSlug()) {
            return null;
        }

        return $this->urlGenerator->generate('editorial_post', [
            'locale' => $locale,
            'postTypeSlug' => $post->getPostType()->getSlug(),
            'slug' => $translation->getSlug(),
        ]);
    }

    private function resolveTermUrl(MenuItemInterface $item, string $locale): ?string
    {
        $term = $this->termRepository->find($item->getTargetId());
        if (!$term instanceof TaxonomyTermInterface) {
            return null;
        }

        $translation = $term->getTranslation($locale) ?? $term->getTranslations()->first();
        if (!$translation || !$translation->getSlug()) {
            return null;
        }

        return $this->urlGenerator->generate('editorial_term', [
            'locale' => $locale,
            'taxonomySlug' => $term->getTaxonomy()->getSlug(),
            'termSlug' => $translation->getSlug(),
        ]);
    }

    private function resolveArchiveUrl(MenuItemInterface $item, string $locale): ?string
    {
        $postType = $this->postTypeRepository->find($item->getTargetId());
        if (!$postType instanceof PostTypeInterface) {
            return null;
        }

        return $this->urlGenerator->generate('editorial_archive', [
            'locale' => $locale,
            'postTypeSlug' => $postType->getSlug(),
        ]);
    }

    private function resolveLabel(MenuItemInterface $item, string $locale): ?string
    {
        // 1. Translation override has priority
        $override = $item->getTranslation($locale)?->getLabel();
        if (null !== $override && '' !== $override) {
            return $override;
        }

        // 2. Natural label from target
        return match ($item->getTargetType()) {
            MenuItemTargetTypeEnum::Home => $this->translator->trans('frontend.menu.home', [], 'messages', $locale),
            MenuItemTargetTypeEnum::FrontLogin => $this->translator->trans('frontend.menu.login', [], 'messages', $locale),
            MenuItemTargetTypeEnum::FrontRegister => $this->translator->trans('frontend.menu.register', [], 'messages', $locale),
            MenuItemTargetTypeEnum::FrontAccount => $this->translator->trans('frontend.menu.account', [], 'messages', $locale),
            MenuItemTargetTypeEnum::FrontLogout => $this->translator->trans('frontend.menu.logout', [], 'messages', $locale),
            MenuItemTargetTypeEnum::Post => $this->postLabel($item, $locale),
            MenuItemTargetTypeEnum::Term => $this->termLabel($item, $locale),
            MenuItemTargetTypeEnum::PostTypeArchive => $this->postTypeRepository->find($item->getTargetId())?->getLabel(),
            MenuItemTargetTypeEnum::CustomUrl => null, // Custom URL items must have a translation override
        };
    }

    private function postLabel(MenuItemInterface $item, string $locale): ?string
    {
        $post = $this->postRepository->find($item->getTargetId());
        if (!$post instanceof PostInterface) {
            return null;
        }

        $translation = $post->getTranslation($locale) ?? $post->getTranslations()->first();

        return $translation->getTitle();
    }

    private function termLabel(MenuItemInterface $item, string $locale): ?string
    {
        $term = $this->termRepository->find($item->getTargetId());
        if (!$term instanceof TaxonomyTermInterface) {
            return null;
        }

        $translation = $term->getTranslation($locale) ?? $term->getTranslations()->first();

        return $translation->getName();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function stripPositions(array &$items): void
    {
        foreach ($items as &$item) {
            unset($item['_position']);
        }
    }
}
