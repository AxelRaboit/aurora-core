<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\View;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Configuration\SettingDefinitionRegistry;
use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the Twig payload for the admin settings page. Iterates the
 * {@see SettingDefinitionRegistry} (built from every contributed
 * {@see ConfigurationTabProviderInterface}),
 * resolves the current persisted value for each field, and decorates `media`
 * fields with a public URL the Vue layer can preview.
 *
 * Wire format kept stable: a `groups` map (tab id → field[]) plus a `tabs`
 * list carrying ordering metadata so the JS no longer needs to hardcode the
 * tab order.
 */
final readonly class SettingsViewBuilder
{
    public function __construct(
        private SettingRepository $settingRepository,
        private DocumentRepository $documentRepository,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private SettingDefinitionRegistry $definitionRegistry,
        private DocumentUrlGenerator $documentUrlGenerator,
        private ModuleAccessChecker $moduleAccessChecker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(bool $isDev = false): array
    {
        $groups = [];
        $tabs = [];

        foreach ($this->definitionRegistry->getTabs() as $tab) {
            if ($tab->devOnly && !$isDev) {
                continue;
            }

            // Hide tabs whose owning module is currently disabled. The
            // settings remain writable through the controller (so an admin
            // re-enabling the module won't have lost their configuration),
            // but the UI stays consistent with what's actually reachable.
            if (null !== $tab->moduleToggle && !$this->moduleAccessChecker->isEnabled($tab->moduleToggle)) {
                continue;
            }

            $fields = [];
            foreach ($tab->fields as $field) {
                $value = $this->settingRepository->get($field->key, $field->defaultValue);

                $fields[] = [
                    'key' => $field->key,
                    'label' => $this->translator->trans($field->labelKey),
                    'description' => $this->translator->trans($field->descriptionKey),
                    'placeholder' => $this->resolvePlaceholder($field),
                    'type' => $field->type,
                    'group' => $tab->id,
                    'value' => $value,
                    'mediaUrl' => 'media' === $field->type ? $this->resolveMediaUrl($value) : null,
                    'options' => $field->options,
                ];
            }

            $groups[$tab->id] = $fields;
            $tabs[] = [
                'id' => $tab->id,
                'priority' => $tab->priority,
                'alwaysVisible' => $tab->alwaysVisible,
                'componentName' => $tab->componentName,
                'devOnly' => $tab->devOnly,
            ];
        }

        return [
            'groups' => $groups,
            'tabs' => $tabs,
            'postSearchPath' => $this->urlGenerator->generate('backend_editorial_posts_search'),
        ];
    }

    /**
     * Resolves the placeholder shown inside the Settings input. Priority:
     *   1. Explicit `placeholderKey` from the enum → translated value.
     *   2. Auto-fallback on `defaultValue` for `text` / `int` / `textarea`
     *      fields where the default is genuinely a usable example
     *      (non-empty, non-`'0'`). Covers the sequence-prefix sea
     *      (`'INV'`, `'DEAL'`, `'ORD'`, …) and the Notes / Assistant
     *      text defaults (`'qwen3:8b'`, `'2048'`, …) without forcing
     *      every enum to wire a per-case translation key.
     *   3. `null` — input renders with a blank placeholder.
     *
     * `bool` / `select` / `media` / `post` fields never get a fallback:
     * they render as their own controls (checkbox, dropdown, picker)
     * where the placeholder slot doesn't exist or wouldn't help.
     */
    private function resolvePlaceholder(SettingFieldDescriptor $field): ?string
    {
        if (null !== $field->placeholderKey) {
            return $this->translator->trans($field->placeholderKey);
        }

        if (!in_array($field->type, ['text', 'int', 'textarea'], true)) {
            return null;
        }

        $default = $field->defaultValue;
        if ('' === $default || '0' === $default) {
            return null;
        }

        return $default;
    }

    public function resolveMediaUrl(?string $rawId): ?string
    {
        if (null === $rawId || '' === $rawId) {
            return null;
        }

        $documentId = (int) $rawId;
        if ($documentId <= 0) {
            return null;
        }

        return $this->documentUrlGenerator->publicUrl($this->documentRepository->find($documentId));
    }
}
