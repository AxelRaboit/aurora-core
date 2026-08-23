<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Menu\Dto\MenuInputInterface;
use Aurora\Module\Editorial\Menu\Dto\MenuItemInputInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Repository\MenuItemRepository;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(MenuManagerInterface::class)]
class MenuManager implements MenuManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly MenuItemRepository $menuItemRepository,
        protected readonly PostRepository $postRepository,
        protected readonly TaxonomyTermRepository $termRepository,
        protected readonly PostTypeRepository $postTypeRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function update(MenuInterface $menu, MenuInputInterface $input): void
    {
        $menu->setName($input->getName());
        $menu->setDescription($input->getDescription());

        $this->entityManager->flush();

        $this->auditMenuUpdated($menu);
    }

    public function createItem(MenuInterface $menu, MenuItemInputInterface $input): MenuItemInterface
    {
        $item = $this->createMenuItem();
        $menu->addItem($item);

        $this->applyInput($item, $input);
        $item->setPosition($this->nextPositionFor($menu, $item));

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->auditItemCreated($item);

        return $item;
    }

    public function updateItem(MenuItemInterface $item, MenuItemInputInterface $input): void
    {
        $this->applyInput($item, $input);

        $this->entityManager->flush();

        $this->auditItemUpdated($item);
    }

    public function deleteItem(MenuItemInterface $item): void
    {
        // Children are promoted rather than removed with the parent: an
        // editor deleting a heading means "drop this label", not "drop the
        // six links under it".
        foreach ($item->getChildren() as $child) {
            $child->setParent($item->getParent());
        }

        $this->auditItemDeleted($item);

        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    public function reorderItems(MenuInterface $menu, array $entries): void
    {
        $itemsById = [];
        foreach ($menu->getItems() as $item) {
            $itemsById[$item->getId()] = $item;
        }

        // The whole intended tree is read before anything moves: checking as
        // we go would compare against a tree half-old and half-new, and miss
        // the cycle that makes the menu unrenderable.
        $parentMap = [];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if (!isset($itemsById[$id])) {
                continue;
            }

            $parentId = $entry['parentId'] ?? null;
            $parentMap[$id] = null !== $parentId && $parentId > 0 ? $parentId : null;
        }

        $this->assertNoCycle($parentMap);

        foreach ($entries as $entry) {
            $item = $itemsById[$entry['id']] ?? null;
            if (null === $item) {
                continue;
            }

            $parentId = $parentMap[$entry['id']] ?? null;
            $item->setParent(null !== $parentId ? ($itemsById[$parentId] ?? null) : null);
            $item->setPosition($entry['position']);
        }

        $this->entityManager->flush();

        $this->auditItemsReordered($menu);
    }

    // ── Hooks: instanciation ──────────────────────────────────────────────────

    protected function createMenuItem(): MenuItemInterface
    {
        return new MenuItem();
    }

    // ── Hooks: hydratation ────────────────────────────────────────────────────

    protected function applyInput(MenuItemInterface $item, MenuItemInputInterface $input): void
    {
        $item->setParent($this->resolveParent($item, $input->getParentId()));
        $item->setTargetType($input->getTargetType());
        $item->setTargetId($this->resolveTargetId($input));
        $item->setCustomUrl($input->getTargetType()->requiresCustomUrl() ? $input->getCustomUrl() : null);
        $item->setOpenInNewTab($input->isOpenInNewTab());
        $item->setCssClass($input->getCssClass());
        $item->setVisibility($input->getVisibility());

        foreach ($input->getTranslations() as $locale => $payload) {
            $item->translate((string) $locale)->setLabel($payload['label']);
        }
    }

    // ── Hooks: audit ──────────────────────────────────────────────────────────

    protected function auditMenuUpdated(MenuInterface $menu): void
    {
        $this->auditLogger->log('editorial', 'menu.updated', 'Menu', $menu->getId(), $this->auditMenuPayload($menu));
    }

    protected function auditItemCreated(MenuItemInterface $item): void
    {
        $this->auditLogger->log('editorial', 'menu.item.created', 'MenuItem', $item->getId(), $this->auditItemPayload($item));
    }

    protected function auditItemUpdated(MenuItemInterface $item): void
    {
        $this->auditLogger->log('editorial', 'menu.item.updated', 'MenuItem', $item->getId(), $this->auditItemPayload($item));
    }

    protected function auditItemDeleted(MenuItemInterface $item): void
    {
        $this->auditLogger->log('editorial', 'menu.item.deleted', 'MenuItem', $item->getId(), $this->auditItemPayload($item));
    }

    protected function auditItemsReordered(MenuInterface $menu): void
    {
        $this->auditLogger->log('editorial', 'menu.item.reordered', 'Menu', $menu->getId(), $this->auditMenuPayload($menu));
    }

    /** @return array<string, mixed> */
    protected function auditMenuPayload(MenuInterface $menu): array
    {
        return ['location' => $menu->getLocation()];
    }

    /** @return array<string, mixed> */
    protected function auditItemPayload(MenuItemInterface $item): array
    {
        return [
            'location' => $item->getMenu()->getLocation(),
            'targetType' => $item->getTargetType()->value,
            'targetId' => $item->getTargetId(),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Checked here rather than trusted from the form: the id arrives from the
     * browser, and an entry pointing at a row that has since gone would render
     * as nothing with no way for the editor to see why.
     */
    private function resolveTargetId(MenuItemInputInterface $input): ?int
    {
        $targetId = $input->getTargetId();
        if (!$input->getTargetType()->requiresTargetId() || null === $targetId) {
            return null;
        }

        $exists = match ($input->getTargetType()) {
            MenuItemTargetTypeEnum::Post => null !== $this->postRepository->find($targetId),
            MenuItemTargetTypeEnum::Term => null !== $this->termRepository->find($targetId),
            MenuItemTargetTypeEnum::PostTypeArchive => null !== $this->postTypeRepository->find($targetId),
            default => false,
        };

        if (!$exists) {
            throw new FieldException('targetId', $this->translator->trans('backend.menus.errors.target_not_found', ['{id}' => $targetId]));
        }

        return $targetId;
    }

    private function resolveParent(MenuItemInterface $item, ?int $parentId): ?MenuItemInterface
    {
        if (null === $parentId) {
            return null;
        }

        $parent = $this->menuItemRepository->find($parentId);
        if (!$parent instanceof MenuItemInterface) {
            return null;
        }

        if ($parent->getMenu()->getId() !== $item->getMenu()->getId()) {
            throw new FieldException('parentId', $this->translator->trans('backend.menus.errors.parent_wrong_menu'));
        }

        if ($parent === $item || $parent->isDescendantOf($item)) {
            throw new FieldException('parentId', $this->translator->trans('backend.menus.errors.self_nested'));
        }

        return $parent;
    }

    /**
     * Last among its siblings. The new entry is already in the collection by
     * now - it had to be, for `resolveParent()` to know which menu it belongs
     * to - so it is skipped here rather than counted as its own sibling.
     */
    private function nextPositionFor(MenuInterface $menu, MenuItemInterface $item): int
    {
        $position = -1;
        $parentId = $item->getParent()?->getId();

        foreach ($menu->getItems() as $sibling) {
            if ($sibling !== $item && $sibling->getParent()?->getId() === $parentId) {
                $position = max($position, $sibling->getPosition());
            }
        }

        return $position + 1;
    }

    /** @param array<int, ?int> $parentMap */
    private function assertNoCycle(array $parentMap): void
    {
        foreach (array_keys($parentMap) as $id) {
            $seen = [$id => true];
            $current = $parentMap[$id];
            while (null !== $current) {
                if (isset($seen[$current])) {
                    throw new InvalidArgumentException($this->translator->trans('backend.menus.errors.reorder_cycle', ['{id}' => $id]));
                }

                $seen[$current] = true;
                $current = $parentMap[$current] ?? null;
            }
        }
    }
}
