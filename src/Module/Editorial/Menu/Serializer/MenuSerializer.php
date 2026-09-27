<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Serializer;

use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Service\MenuLocationRegistry;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MenuSerializerInterface::class)]
class MenuSerializer implements MenuSerializerInterface
{
    public function __construct(
        protected readonly MenuLocationRegistry $locationRegistry,
        protected readonly PostRepository $postRepository,
        protected readonly TaxonomyTermRepository $termRepository,
        protected readonly PostTypeRepository $postTypeRepository,
    ) {}

    public function serialize(MenuInterface $menu): array
    {
        $targetLabels = $this->targetLabels($menu->getItems());

        $items = [];
        foreach ($menu->getItems() as $item) {
            $items[] = $this->serializeItem($item, $targetLabels);
        }

        usort($items, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return [
            'id' => $menu->getId(),
            'name' => $menu->getName(),
            'location' => $menu->getLocation(),
            'description' => $menu->getDescription(),
            // A menu bound to a location no module declares any more still
            // exists in the database. The screen says so rather than showing
            // it as normal - its entries render nowhere.
            'locationKnown' => $this->locationRegistry->has($menu->getLocation()),
            'items' => $items,
        ];
    }

    public function serializeItem(MenuItemInterface $item, ?array $targetLabels = null): array
    {
        $translations = [];
        foreach ($item->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = ['label' => $translation->getLabel()];
        }

        return [
            'id' => $item->getId(),
            'parentId' => $item->getParent()?->getId(),
            'position' => $item->getPosition(),
            'targetType' => $item->getTargetType()->value,
            'targetId' => $item->getTargetId(),
            'customUrl' => $item->getCustomUrl(),
            'sectionPostTypeId' => $item->getSectionPostTypeId(),
            'openInNewTab' => $item->isOpenInNewTab(),
            'cssClass' => $item->getCssClass(),
            'visibility' => $item->getVisibility()->value,
            'reference' => $item->getReference(),
            'translations' => $translations,
            // What the entry points at, named. The tree shows this under the
            // label so an editor can tell two "Read more" entries apart, and
            // a null tells them the target is gone.
            'targetLabel' => null === $targetLabels || null === $item->getTargetId()
                ? $this->targetLabel($item)
                : $targetLabels[$item->getTargetType()->value][$item->getTargetId()] ?? null,
        ];
    }

    /**
     * What every entry of a menu points at, named, in one query per kind of
     * target. The screen used to look each target up on its own, then load
     * its translations: two queries an entry, on a screen that lists every
     * menu. The public rendering already did it this way.
     *
     * @param iterable<MenuItemInterface> $items
     *
     * @return array<string, array<int, string|null>>
     */
    private function targetLabels(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (null !== $item->getTargetId()) {
                $ids[$item->getTargetType()->value][] = $item->getTargetId();
            }
        }

        $labels = [];

        foreach ($this->postRepository->findForDisplay(array_values(array_unique($ids[MenuItemTargetTypeEnum::Post->value] ?? []))) as $post) {
            $labels[MenuItemTargetTypeEnum::Post->value][(int) $post->getId()] = ($post->getTranslations()->first() ?: null)?->getTitle();
        }

        foreach ($this->termRepository->findForDisplay(array_values(array_unique($ids[MenuItemTargetTypeEnum::Term->value] ?? []))) as $term) {
            $labels[MenuItemTargetTypeEnum::Term->value][(int) $term->getId()] = ($term->getTranslations()->first() ?: null)?->getName();
        }

        $typeIds = array_values(array_unique($ids[MenuItemTargetTypeEnum::PostTypeArchive->value] ?? []));
        foreach ([] === $typeIds ? [] : $this->postTypeRepository->findBy(['id' => $typeIds]) as $postType) {
            $labels[MenuItemTargetTypeEnum::PostTypeArchive->value][(int) $postType->getId()] = $postType->getLabel();
        }

        return $labels;
    }

    private function targetLabel(MenuItemInterface $item): ?string
    {
        $targetId = $item->getTargetId();
        if (null === $targetId) {
            return $item->getCustomUrl();
        }

        // `first()` on an empty Doctrine collection is `false`, not null, so
        // the `?:` is doing real work here rather than being defensive noise.
        return match ($item->getTargetType()) {
            MenuItemTargetTypeEnum::Post => ($this->postRepository->find($targetId)?->getTranslations()->first() ?: null)?->getTitle(),
            MenuItemTargetTypeEnum::Term => ($this->termRepository->find($targetId)?->getTranslations()->first() ?: null)?->getName(),
            MenuItemTargetTypeEnum::PostTypeArchive => $this->postTypeRepository->find($targetId)?->getLabel(),
            default => null,
        };
    }
}
