<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;

final readonly class EditorialConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        $fields = [];
        foreach (EditorialSettingEnum::cases() as $case) {
            $fields[] = new SettingFieldDescriptor(
                key: $case->getKey(),
                type: $case->getType(),
                labelKey: $case->getLabel(),
                descriptionKey: $case->getDescription(),
                defaultValue: $case->getDefaultValue(),
                placeholderKey: $case->getPlaceholder(),
            );
        }

        return [
            new ConfigurationTab(id: 'sequences', priority: 90, fields: $fields),
        ];
    }
}
