<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Configuration;

use Aurora\Core\Frontend\Service\Registry;
use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use DateTimeZone;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

/**
 * Wraps the legacy {@see ApplicationParameterEnum} as Aurora's built-in
 * contribution to the Settings page. Each admin-accessible enum group
 * becomes a {@see ConfigurationTab}, with priority preserved to match the
 * previous hardcoded JS order. Select-type options (timezone, locale,
 * default front) are resolved here, dynamically per request.
 *
 * As module-owned settings get migrated out of the enum, their fields will
 * leave this provider and reappear in their own module's provider - no
 * change required on the Settings page itself.
 */
final readonly class CoreConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    /**
     * Group → priority. Mirrors the previously hardcoded GROUP_ORDER in
     * `useSettingsTabs.js`. Groups missing from this map are not surfaced
     * (preserves prior behavior: `media`, `crm`, `ecommerce` were declared
     * admin-accessible on the enum but filtered out by the JS - kept hidden
     * here to avoid a surprise UI change. Re-enable by adding them when the
     * corresponding screens are designed).
     */
    private const array GROUP_PRIORITY = [
        'general' => 10,
        'reading' => 20,
        'localization' => 30,
        'branding' => 40,
        'appearance' => 50,
        'seo' => 60,
        'system' => 70,
        'email' => 80,
        'media' => 85,      // upload limits - visible to all admins
        'sequences' => 90,  // internal prefixes - dev only
        'navigation' => 100,
    ];

    /**
     * Groups whose body is drawn by a custom Vue component, so the tab must
     * render even if its `$fields` happens to be empty. Each entry is also
     * mapped to its `componentName` (see Vue-side `tabRegistry.js`).
     */
    private const array CUSTOM_COMPONENT_GROUPS = [
        'navigation' => 'navigation',
        'appearance' => 'appearance',
    ];

    /** Groups that should only appear for ROLE_DEV users. */
    private const array DEV_ONLY_GROUPS = ['sequences', 'media'];

    public function __construct(
        private SettingRepository $settingRepository,
        private Registry $frontendRegistry,
        private TranslatorInterface $translator,
    ) {}

    public function getTabs(): array
    {
        $fieldsByGroup = [];
        foreach (ApplicationParameterEnum::cases() as $parameter) {
            if (!$parameter->isAdminAccessible()) {
                continue;
            }

            $group = $parameter->getGroup();
            if (!isset(self::GROUP_PRIORITY[$group])) {
                continue;
            }

            $fieldsByGroup[$group][] = new SettingFieldDescriptor(
                key: $parameter->getKey(),
                type: $parameter->getType(),
                labelKey: $parameter->getLabel(),
                descriptionKey: $parameter->getDescription(),
                defaultValue: $parameter->getDefaultValue(),
                options: $this->resolveSelectOptions($parameter),
                placeholderKey: $parameter->getPlaceholder(),
            );
        }

        $tabs = [];
        foreach (self::GROUP_PRIORITY as $group => $priority) {
            $fields = $fieldsByGroup[$group] ?? [];
            $componentName = self::CUSTOM_COMPONENT_GROUPS[$group] ?? null;
            if ([] === $fields && null === $componentName) {
                continue;
            }

            $tabs[] = new ConfigurationTab(
                id: $group,
                priority: $priority,
                fields: $fields,
                alwaysVisible: null !== $componentName,
                devOnly: in_array($group, self::DEV_ONLY_GROUPS, true),
                componentName: $componentName,
            );
        }

        return $tabs;
    }

    /**
     * @return list<array{value: string, label: string}>|null
     */
    private function resolveSelectOptions(ApplicationParameterEnum $parameter): ?array
    {
        if (ApplicationParameterEnum::DefaultFront === $parameter) {
            return array_values(array_map(
                static fn ($front): array => ['value' => $front->getSlug(), 'label' => $front->getLabel()],
                array_filter(
                    $this->frontendRegistry->all(),
                    fn ($front): bool => null === $front->getModuleSettingKey()
                        || $this->settingRepository->getBoolean($front->getModuleSettingKey(), true),
                ),
            ));
        }

        if (in_array($parameter, [ApplicationParameterEnum::DefaultLocale, ApplicationParameterEnum::EmailLocale], true)) {
            $options = array_map(
                fn (LocaleEnum $locale): array => [
                    'value' => $locale->value,
                    'label' => $this->translator->trans('shared.locales.'.$locale->value),
                ],
                LocaleEnum::cases(),
            );

            if (ApplicationParameterEnum::EmailLocale === $parameter) {
                array_unshift($options, [
                    'value' => '',
                    'label' => $this->translator->trans('backend.parameters.email_locale_auto'),
                ]);
            }

            return $options;
        }

        if (ApplicationParameterEnum::Timezone === $parameter) {
            return array_map(
                static fn (string $tz): array => ['value' => $tz, 'label' => $tz],
                DateTimeZone::listIdentifiers(),
            );
        }

        return null;
    }
}
