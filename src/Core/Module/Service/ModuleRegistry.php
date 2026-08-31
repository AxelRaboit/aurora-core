<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Service;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavItemResolver;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use JsonException;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ModuleRegistry
{
    /** @param iterable<ModuleInterface> $modules */
    public function __construct(
        private iterable $modules,
        private NavItemResolver $navItemResolver,
        private Security $userSecurity,
        private SettingRepository $settingRepository,
    ) {}

    /**
     * Returns resolved nav sections (permissions filtered, paths generated, sorted by priority).
     *
     * Sections and items hidden by the current user (via their personal sidemenu
     * preferences) are excluded from the returned structure but remain reachable
     * by direct URL - the hide is a display preference, not an access control.
     *
     * After the priority-based sort, applies the admin-defined order overrides
     * stored in `nav_section_order` and `nav_item_order`. IDs / routes present
     * in the override come first, in the override's order; anything missing
     * from the override (typically a newly-installed module) keeps its natural
     * priority position and lands after the explicitly-ordered entries.
     *
     * @return array<int, array{id: string, items: array<int, array{route: string, path: string, labelKey: string, icon: string, activeColor: string}>}>
     */
    public function getNavSections(): array
    {
        $user = $this->userSecurity->getUser();
        $hiddenSections = $user instanceof CoreUserInterface ? $user->getHiddenNavSections() : [];
        $hiddenItems = $user instanceof CoreUserInterface ? $user->getHiddenNavItems() : [];

        $sections = [];
        $insertion = 0;

        foreach ($this->modules as $module) {
            foreach ($module->getNavSections() as $section) {
                if (in_array($section->id, $hiddenSections, true)) {
                    continue;
                }

                $resolvedItems = [];

                foreach ($section->items as $item) {
                    $resolved = $this->resolveItem($item, $hiddenItems);
                    if (null !== $resolved) {
                        $resolvedItems[] = $resolved;
                    }
                }

                if ([] !== $resolvedItems) {
                    $sections[] = [
                        'id' => $section->id,
                        'items' => $resolvedItems,
                        'priority' => $section->priority,
                        'insertion' => $insertion++,
                    ];
                }
            }
        }

        usort($sections, static fn (array $a, array $b): int => [$a['priority'], $a['insertion']] <=> [$b['priority'], $b['insertion']]);

        $sectionOrder = $this->readSectionOrder();
        $itemOrder = $this->readItemOrder();

        $sections = $this->applySectionOrder($sections, $sectionOrder);
        $sections = $this->applyItemOrder($sections, $itemOrder);

        return array_map(static fn (array $section): array => [
            'id' => $section['id'],
            'items' => $section['items'],
        ], $sections);
    }

    /**
     * Returns the full nav structure available to the current user (filtered only by
     * privilege), annotated with the user's current hidden flags. Used by the
     * sidemenu preferences UI so users can toggle visibility for every available
     * section/item - including ones they have already hidden.
     *
     * @return array<int, array{id: string, hidden: bool, items: array<int, array<string, mixed>>}>
     */
    public function getNavPreferences(): array
    {
        $user = $this->userSecurity->getUser();
        $hiddenSections = $user instanceof CoreUserInterface ? $user->getHiddenNavSections() : [];
        $hiddenItems = $user instanceof CoreUserInterface ? $user->getHiddenNavItems() : [];

        $sections = [];
        $insertion = 0;

        foreach ($this->modules as $module) {
            foreach ($module->getNavSections() as $section) {
                $resolvedItems = [];

                foreach ($section->items as $item) {
                    $resolved = $this->resolveItem($item);
                    if (null !== $resolved) {
                        $resolvedItems[] = $this->annotateHiddenFlags($resolved, $hiddenItems);
                    }
                }

                if ([] !== $resolvedItems) {
                    $sections[] = [
                        'id' => $section->id,
                        'hidden' => in_array($section->id, $hiddenSections, true),
                        'items' => $resolvedItems,
                        'priority' => $section->priority,
                        'insertion' => $insertion++,
                    ];
                }
            }
        }

        usort($sections, static fn (array $a, array $b): int => [$a['priority'], $a['insertion']] <=> [$b['priority'], $b['insertion']]);

        $sectionOrder = $this->readSectionOrder();
        $itemOrder = $this->readItemOrder();

        $sections = $this->applySectionOrder($sections, $sectionOrder);
        $sections = $this->applyItemOrder($sections, $itemOrder);

        return array_map(static fn (array $section): array => [
            'id' => $section['id'],
            'hidden' => $section['hidden'],
            'items' => $section['items'],
        ], $sections);
    }

    /**
     * @param array<string, mixed> $resolved
     * @param list<string>         $hiddenItems
     *
     * @return array<string, mixed>
     */
    private function annotateHiddenFlags(array $resolved, array $hiddenItems): array
    {
        $resolved['hidden'] = in_array($resolved['key'], $hiddenItems, true);
        $resolved['children'] = array_map(
            fn (array $child): array => $this->annotateHiddenFlags($child, $hiddenItems),
            $resolved['children'],
        );

        return $resolved;
    }

    /**
     * Shared with the module view - see {@see NavItemResolver}, which owns the
     * privilege check, the path generation and the hidden-item filter.
     *
     * @param list<string> $hiddenItems user-hidden NavItem route names
     *
     * @return array<string, mixed>|null null when the item is filtered by role or hidden by the user
     */
    private function resolveItem(NavItem $item, array $hiddenItems = []): ?array
    {
        return $this->navItemResolver->resolve($item, $hiddenItems);
    }

    /**
     * Reorders the resolved sections list by the admin's preferred order. IDs
     * not present in the override stay in their natural priority position and
     * are appended after the explicitly-ordered entries.
     *
     * @param list<array<string, mixed>> $sections
     * @param list<string>               $order
     *
     * @return list<array<string, mixed>>
     */
    private function applySectionOrder(array $sections, array $order): array
    {
        if ([] === $order || [] === $sections) {
            return $sections;
        }

        $byId = [];
        foreach ($sections as $section) {
            $byId[$section['id']] = $section;
        }

        $ordered = [];
        foreach ($order as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        // Anything left in $byId wasn't mentioned in the override - keep its
        // natural priority/insertion order (still iterable in the original list
        // order since we built $byId from the already-sorted $sections).
        return array_merge($ordered, array_values($byId));
    }

    /**
     * Same logic as `applySectionOrder` but per-section: each section in the
     * result has its `items` reordered if its id appears as a key in $order.
     *
     * @param list<array<string, mixed>>  $sections
     * @param array<string, list<string>> $order    sectionId → list of NavItem route names
     *
     * @return list<array<string, mixed>>
     */
    private function applyItemOrder(array $sections, array $order): array
    {
        if ([] === $order) {
            return $sections;
        }

        foreach ($sections as &$section) {
            $sectionOrder = $order[$section['id']] ?? null;
            if (null === $sectionOrder) {
                continue;
            }

            if ([] === $sectionOrder) {
                continue;
            }

            $itemKey = isset($section['items'][0]['key']) ? 'key' : 'route';
            $byKey = [];
            foreach ($section['items'] as $item) {
                $byKey[$item[$itemKey]] = $item;
            }

            $ordered = [];
            foreach ($sectionOrder as $key) {
                if (isset($byKey[$key])) {
                    $ordered[] = $byKey[$key];
                    unset($byKey[$key]);
                }
            }

            $section['items'] = array_merge($ordered, array_values($byKey));
        }

        unset($section);

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function readSectionOrder(): array
    {
        return $this->decodeJsonList($this->settingRepository->getOrDefault(ApplicationParameterEnum::NavSectionOrder));
    }

    /**
     * @return array<string, list<string>>
     */
    private function readItemOrder(): array
    {
        try {
            $decoded = json_decode(
                $this->settingRepository->getOrDefault(ApplicationParameterEnum::NavItemOrder),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $sectionId => $items) {
            if (!is_string($sectionId)) {
                continue;
            }

            if (!is_array($items)) {
                continue;
            }

            $clean[$sectionId] = array_values(array_filter($items, is_string(...)));
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private function decodeJsonList(string $raw): array
    {
        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }
}
