<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Serializer;

use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(MenuItemSerializerInterface::class)]
class MenuItemSerializer implements MenuItemSerializerInterface
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly TaxonomyTermRepository $termRepository,
        private readonly PostTypeRepository $postTypeRepository,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @param array<int, PostInterface>         $postCache     keyed by id
     * @param array<int, TaxonomyTermInterface> $termCache     keyed by id
     * @param array<int, PostTypeInterface>     $postTypeCache keyed by id
     *
     * @return array<string, mixed>
     */
    public function serialize(MenuItemInterface $item, array $postCache = [], array $termCache = [], array $postTypeCache = []): array
    {
        $translations = [];
        foreach ($item->getTranslations() as $translation) {
            $translations[$translation->getLocale()] = $translation->getLabel();
        }

        $children = [];
        foreach ($item->getChildren() as $child) {
            $children[] = $this->serialize($child, $postCache, $termCache, $postTypeCache);
        }

        return [
            'id' => $item->getId(),
            'targetType' => $item->getTargetType()->value,
            'targetId' => $item->getTargetId(),
            'customUrl' => $item->getCustomUrl(),
            'openInNewTab' => $item->isOpenInNewTab(),
            'cssClass' => $item->getCssClass(),
            'visibility' => $item->getVisibility()->value,
            'position' => $item->getPosition(),
            'parentId' => $item->getParent()?->getId(),
            'translations' => $translations,
            'targetPreview' => $this->resolveTargetPreview($item, $postCache, $termCache, $postTypeCache),
            'children' => $children,
        ];
    }

    /**
     * Pre-loads referenced Posts/Terms/PostTypes in batches so the recursive
     * serialization stays free of N+1 queries.
     *
     * @param iterable<MenuItemInterface> $items
     *
     * @return array{posts: array<int, PostInterface>, terms: array<int, TaxonomyTermInterface>, postTypes: array<int, PostTypeInterface>}
     */
    public function preloadTargets(iterable $items): array
    {
        $postIds = [];
        $termIds = [];
        $postTypeIds = [];

        $this->collectTargetIds($items, $postIds, $termIds, $postTypeIds);

        $posts = [];
        foreach ($this->postRepository->findByIds(array_values(array_unique($postIds))) as $post) {
            $posts[$post->getId()] = $post;
        }

        $terms = [];
        foreach ($this->termRepository->findByIds(array_values(array_unique($termIds))) as $term) {
            $terms[$term->getId()] = $term;
        }

        $postTypes = [];
        foreach ($this->postTypeRepository->findByIds(array_values(array_unique($postTypeIds))) as $postType) {
            $postTypes[$postType->getId()] = $postType;
        }

        return ['posts' => $posts, 'terms' => $terms, 'postTypes' => $postTypes];
    }

    /**
     * @param iterable<MenuItemInterface> $items
     * @param list<int>                   $postIds
     * @param list<int>                   $termIds
     * @param list<int>                   $postTypeIds
     */
    private function collectTargetIds(iterable $items, array &$postIds, array &$termIds, array &$postTypeIds): void
    {
        foreach ($items as $item) {
            $targetId = $item->getTargetId();
            if (null !== $targetId) {
                match ($item->getTargetType()) {
                    MenuItemTargetTypeEnum::Post => $postIds[] = $targetId,
                    MenuItemTargetTypeEnum::Term => $termIds[] = $targetId,
                    MenuItemTargetTypeEnum::PostTypeArchive => $postTypeIds[] = $targetId,
                    default => null,
                };
            }

            $this->collectTargetIds($item->getChildren(), $postIds, $termIds, $postTypeIds);
        }
    }

    /**
     * @param array<int, PostInterface>         $postCache
     * @param array<int, TaxonomyTermInterface> $termCache
     * @param array<int, PostTypeInterface>     $postTypeCache
     *
     * @return array<string, mixed>
     */
    private function resolveTargetPreview(MenuItemInterface $item, array $postCache, array $termCache, array $postTypeCache): array
    {
        return match ($item->getTargetType()) {
            MenuItemTargetTypeEnum::Home => ['label' => $this->translator->trans('frontend.menu.home'), 'hint' => '/'],
            MenuItemTargetTypeEnum::FrontLogin => ['label' => $this->translator->trans('frontend.menu.login'), 'hint' => '/login'],
            MenuItemTargetTypeEnum::FrontRegister => ['label' => $this->translator->trans('frontend.menu.register'), 'hint' => '/register'],
            MenuItemTargetTypeEnum::FrontAccount => ['label' => $this->translator->trans('frontend.menu.account'), 'hint' => '/account'],
            MenuItemTargetTypeEnum::FrontLogout => ['label' => $this->translator->trans('frontend.menu.logout'), 'hint' => '/logout'],
            MenuItemTargetTypeEnum::CustomUrl => ['label' => $item->getCustomUrl() ?? '', 'hint' => $item->getCustomUrl() ?? ''],
            MenuItemTargetTypeEnum::Post => $this->postPreview($item, $postCache),
            MenuItemTargetTypeEnum::Term => $this->termPreview($item, $termCache),
            MenuItemTargetTypeEnum::PostTypeArchive => $this->archivePreview($item, $postTypeCache),
        };
    }

    /**
     * @param array<int, PostInterface> $postCache
     *
     * @return array<string, mixed>
     */
    private function postPreview(MenuItemInterface $item, array $postCache): array
    {
        $targetId = $item->getTargetId();
        $post = null !== $targetId ? ($postCache[$targetId] ?? $this->postRepository->find($targetId)) : null;
        if (null === $post) {
            return [
                'label' => $this->translator->trans('backend.menus.preview.post_deleted'),
                'hint' => '#'.$item->getTargetId(),
                'missing' => true,
            ];
        }

        $translation = $post->getTranslations()->first() ?: null;

        return [
            'label' => $translation?->getTitle() ?? $this->translator->trans('backend.menus.preview.untitled'),
            'hint' => sprintf('/%s/%s', $post->getPostType()->getSlug(), $translation?->getSlug() ?? ''),
        ];
    }

    /**
     * @param array<int, TaxonomyTermInterface> $termCache
     *
     * @return array<string, mixed>
     */
    private function termPreview(MenuItemInterface $item, array $termCache): array
    {
        $targetId = $item->getTargetId();
        $term = null !== $targetId ? ($termCache[$targetId] ?? $this->termRepository->find($targetId)) : null;
        if (null === $term) {
            return [
                'label' => $this->translator->trans('backend.menus.preview.term_deleted'),
                'hint' => '#'.$item->getTargetId(),
                'missing' => true,
            ];
        }

        $translation = $term->getTranslations()->first() ?: null;

        return [
            'label' => $translation?->getName() ?? $this->translator->trans('backend.menus.preview.unnamed'),
            'hint' => sprintf('/%s/%s', $term->getTaxonomy()->getSlug(), $translation?->getSlug() ?? ''),
        ];
    }

    /**
     * @param array<int, PostTypeInterface> $postTypeCache
     *
     * @return array<string, mixed>
     */
    private function archivePreview(MenuItemInterface $item, array $postTypeCache): array
    {
        $targetId = $item->getTargetId();
        $postType = null !== $targetId ? ($postTypeCache[$targetId] ?? $this->postTypeRepository->find($targetId)) : null;
        if (null === $postType) {
            return [
                'label' => $this->translator->trans('backend.menus.preview.post_type_deleted'),
                'hint' => '#'.$item->getTargetId(),
                'missing' => true,
            ];
        }

        return ['label' => $postType->getLabel(), 'hint' => '/'.$postType->getSlug()];
    }
}
