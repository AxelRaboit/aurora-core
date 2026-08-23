<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Configuration;

/**
 * Single source of truth for which settings exist, which tab they belong to,
 * and how they should be validated/rendered. Aggregates every
 * {@see ConfigurationTabProviderInterface} into one indexed view, used by:
 *
 *  - {@see SettingsViewBuilder} to build the
 *    grouped Twig payload.
 *  - {@see SettingsController} to
 *    decide whether an incoming `key` is admin-writable.
 *
 * The registry replaces direct `ApplicationParameterEnum::tryFrom(...)` calls
 * downstream, which is what makes module-contributed settings possible.
 */
final class SettingDefinitionRegistry
{
    /**
     * @var list<ConfigurationTab>|null resolved on first access
     */
    private ?array $tabs = null;

    /**
     * @var array<string, SettingFieldDescriptor>|null key → descriptor lookup, built lazily
     */
    private ?array $fieldsByKey = null;

    /**
     * @param iterable<ConfigurationTabProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * Returns all contributed tabs sorted by priority (ascending).
     *
     * Tabs sharing the same `$id` across providers are MERGED: their fields
     * are concatenated in provider iteration order, the lowest priority wins
     * for the placement of the merged tab, and `$alwaysVisible` is OR-ed.
     * This is what lets several modules feed a single shared tab - most
     * notably `sequences`, where each module contributes its own prefix
     * settings to one unified screen.
     *
     * @return list<ConfigurationTab>
     */
    public function getTabs(): array
    {
        if (null !== $this->tabs) {
            return $this->tabs;
        }

        /** @var array<string, ConfigurationTab> $byId */
        $byId = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getTabs() as $tab) {
                if (!isset($byId[$tab->id])) {
                    $byId[$tab->id] = $tab;

                    continue;
                }

                $existing = $byId[$tab->id];
                $byId[$tab->id] = new ConfigurationTab(
                    id: $existing->id,
                    priority: min($existing->priority, $tab->priority),
                    fields: [...$existing->fields, ...$tab->fields],
                    alwaysVisible: $existing->alwaysVisible || $tab->alwaysVisible,
                    devOnly: $existing->devOnly || $tab->devOnly,
                    componentName: $existing->componentName ?? $tab->componentName,
                    // Module gating is a property of the tab as a whole, not
                    // a per-provider opinion. Two providers contributing to
                    // the same tab id are expected to either both gate on
                    // the same module or both leave it null. We keep the
                    // first non-null value so providers don't need to
                    // coordinate the moduleToggle when one of them might
                    // be the lone gatekeeper.
                    moduleToggle: $existing->moduleToggle ?? $tab->moduleToggle,
                );
            }
        }

        $tabs = array_values($byId);
        usort($tabs, static fn (ConfigurationTab $a, ConfigurationTab $b): int => $a->priority <=> $b->priority);

        return $this->tabs = $tabs;
    }

    public function getField(string $key): ?SettingFieldDescriptor
    {
        return $this->fieldsByKey()[$key] ?? null;
    }

    /**
     * Convenience predicate replacing
     * `ApplicationParameterEnum::tryFrom($key)?->isAdminAccessible()`. Any key
     * surfaced by a registered tab is admin-accessible by construction.
     */
    public function isAdminAccessible(string $key): bool
    {
        return isset($this->fieldsByKey()[$key]);
    }

    /**
     * True when the key belongs to a tab marked `devOnly`. Such keys must
     * only be written by users with ROLE_DEV - the controller enforces this
     * at the HTTP boundary so it cannot be bypassed by a crafted POST even
     * when the key IS technically in the registry.
     */
    public function isDevOnly(string $key): bool
    {
        return $this->devOnlyKeys()[$key] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    private function devOnlyKeys(): array
    {
        static $map = null;
        if (null !== $map) {
            return $map;
        }

        $map = [];
        foreach ($this->getTabs() as $tab) {
            if (!$tab->devOnly) {
                continue;
            }

            foreach ($tab->fields as $field) {
                $map[$field->key] = true;
            }
        }

        return $map;
    }

    /**
     * @return array<string, SettingFieldDescriptor>
     */
    private function fieldsByKey(): array
    {
        if (null !== $this->fieldsByKey) {
            return $this->fieldsByKey;
        }

        $map = [];
        foreach ($this->getTabs() as $tab) {
            foreach ($tab->fields as $field) {
                $map[$field->key] = $field;
            }
        }

        return $this->fieldsByKey = $map;
    }
}
