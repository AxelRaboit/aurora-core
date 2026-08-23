<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Toggle;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;

/**
 * Aggregates module toggles declared by all registered modules that
 * implement {@see ModuleToggleProviderInterface}. Single source of truth
 * for the cascade graph and the per-user access picker list.
 *
 * The same module list as {@see ModuleRegistry} is injected (DI tag), so
 * any module - core or aurora-client - contributes its toggles by
 * implementing the provider interface. No central enum to edit.
 */
final class ModuleToggleRegistry
{
    /** @var array<string, ModuleToggle>|null */
    private ?array $byKey = null;

    /** @param iterable<ModuleInterface> $modules */
    public function __construct(private readonly iterable $modules) {}

    /** @return array<string, ModuleToggle> indexed by key */
    public function getAll(): array
    {
        return $this->byKey ??= $this->build();
    }

    public function get(string $key): ?ModuleToggle
    {
        return $this->getAll()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->getAll()[$key]);
    }

    /**
     * Returns toggles that represent the "top-level" entry of a module -
     * one per module - used by the admin per-user access picker.
     *
     * @return list<ModuleToggle>
     */
    public function getTopLevel(): array
    {
        return array_values(array_filter(
            $this->getAll(),
            static fn (ModuleToggle $toggle): bool => $toggle->isTopLevel(),
        ));
    }

    /**
     * Returns the toggles whose `parentKey` matches the given key (one level
     * only - callers recurse if they need the full subtree).
     *
     * @return list<ModuleToggle>
     */
    public function getChildrenOf(string $parentKey): array
    {
        return array_values(array_filter(
            $this->getAll(),
            static fn (ModuleToggle $toggle): bool => $toggle->parentKey === $parentKey,
        ));
    }

    /**
     * Sub-toggles that nest under the given top-level toggle for DISPLAY in the
     * modules dashboard (structural grouping via `displayParentKey`, vs the
     * cascade grouping of {@see getChildrenOf()}).
     *
     * @return list<ModuleToggle>
     */
    public function getDisplayChildrenOf(string $parentKey): array
    {
        return array_values(array_filter(
            $this->getAll(),
            static fn (ModuleToggle $toggle): bool => $toggle->displayParentKey === $parentKey,
        ));
    }

    /**
     * Top-level toggles for the modules dashboard - those with no structural
     * parent (`displayParentKey === null`). Distinct from {@see getTopLevel()}
     * (moduleId-based): a few standalone toggles (e.g. an ecommerce/photo
     * "frontend" switch) are top-level cards without being a module's root.
     *
     * @return list<ModuleToggle>
     */
    public function getDisplayTopLevel(): array
    {
        return array_values(array_filter(
            $this->getAll(),
            static fn (ModuleToggle $toggle): bool => null === $toggle->displayParentKey,
        ));
    }

    /**
     * All transitive descendant keys of a toggle (children, grandchildren, …)
     * via the `parentKey` graph - i.e. the set to force OFF when this toggle is
     * disabled. Replaces `ModuleParameterEnum::getCascadeDisableTargets()` so the
     * cascade is computed from the aggregated toggles, not from a central enum.
     *
     * @return list<string>
     */
    public function getDescendantKeys(string $key): array
    {
        $out = [];
        foreach ($this->getChildrenOf($key) as $child) {
            $out[] = $child->key;
            foreach ($this->getDescendantKeys($child->key) as $deeper) {
                $out[] = $deeper;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, ModuleToggle> */
    private function build(): array
    {
        $index = [];
        foreach ($this->modules as $module) {
            if (!$module instanceof ModuleToggleProviderInterface) {
                continue;
            }

            foreach ($module->getToggles() as $toggle) {
                $index[$toggle->key] = $toggle;
            }
        }

        return $index;
    }
}
