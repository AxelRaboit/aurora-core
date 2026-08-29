<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Surfaces the Markdown-notes module's settings as a dedicated tab on
 * the `/backend/configuration/settings` page. The registry merges tabs with the same
 * id from every provider, so adding another `case` to
 * {@see MarkdownNoteSettingEnum} immediately shows up in the same tab.
 *
 * Priority 110 places the tab right after Core's built-in tabs (10-100)
 * - module-contributed settings live below the platform-wide ones.
 */
final readonly class NotesMarkdownConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        $fields = [];
        foreach (MarkdownNoteSettingEnum::cases() as $case) {
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
            new ConfigurationTab(
                id: 'notes',
                priority: 110,
                fields: $fields,
                moduleToggle: ModuleParameterEnum::NotesBackend->value,
            ),
        ];
    }
}
