<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;
{{MODULE_TOGGLE_USE}}
/**
 * Contributes the "{{MODULE_LABEL}}" tab to the admin Settings page. Edit
 * {@see {{MODULE}}SettingEnum} to add/change settings - this provider
 * automatically builds the tab from the enum cases.
 *
 * The `{{MODULE_ID}}` tab is gated on the module's top-level toggle
 * (see `TAB_MODULE_TOGGLE` below): disabling the module in
 * `/dev/dashboard/modules` hides the whole tab from `/backend/settings`
 * automatically. Shared tabs (e.g. `sequences`) keep `moduleToggle: null`.
 */
final readonly class {{MODULE}}ConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    private const array TAB_PRIORITY = [
        '{{MODULE_ID}}' => 100,
    ];

    private const array TAB_MODULE_TOGGLE = [
        '{{MODULE_ID}}' => {{MODULE_TOGGLE_LITERAL}},
    ];

    public function getTabs(): array
    {
        $fieldsByGroup = [];
        foreach ({{MODULE}}SettingEnum::cases() as $case) {
            $fieldsByGroup[$case->getGroup()][] = new SettingFieldDescriptor(
                key: $case->getKey(),
                type: $case->getType(),
                labelKey: $case->getLabel(),
                descriptionKey: $case->getDescription(),
                defaultValue: $case->getDefaultValue(),
                placeholderKey: $case->getPlaceholder(),
            );
        }

        $tabs = [];
        foreach ($fieldsByGroup as $group => $fields) {
            $tabs[] = new ConfigurationTab(
                id: $group,
                priority: self::TAB_PRIORITY[$group] ?? 100,
                fields: $fields,
                moduleToggle: self::TAB_MODULE_TOGGLE[$group] ?? null,
            );
        }

        return $tabs;
    }
}
