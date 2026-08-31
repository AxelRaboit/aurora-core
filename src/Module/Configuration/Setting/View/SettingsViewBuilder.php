<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\View;

use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;
use Aurora\Module\Configuration\Setting\Configuration\SettingsTabAccess;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
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
        private TranslatorInterface $translator,
        private SettingsTabAccess $tabAccess,
        private DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    /**
     * The payload for one settings tab.
     *
     * It used to build every tab at once, because the page drew them all and
     * switched between them in the browser. Now that a tab is a URL, only the
     * one being looked at is worth resolving - and resolving a `media` field
     * costs a document lookup and a URL generation, per field, for tabs nobody
     * asked for.
     *
     * `groups` keeps its shape - a map keyed by tab id, now holding one entry -
     * so the generic field renderer and every component in `tabRegistry.js` read
     * the payload they already read.
     *
     * Which tabs are visible is `SettingsTabAccess`'s answer now, shared with
     * the controller that validates the `{tab}` in the URL and with the module
     * view that lists them in the side menu.
     *
     * @return array<string, mixed>
     */
    public function tabView(string $activeTab): array
    {
        $groups = [];
        $tabs = [];

        foreach ($this->tabAccess->visibleTabs() as $tab) {
            $tabs[] = [
                'id' => $tab->id,
                'priority' => $tab->priority,
                'alwaysVisible' => $tab->alwaysVisible,
                'componentName' => $tab->componentName,
                'devOnly' => $tab->devOnly,
            ];

            if ($tab->id !== $activeTab) {
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
        }

        return [
            'groups' => $groups,
            'tabs' => $tabs,
            'activeTab' => $activeTab,
            // No content module ships a post-search endpoint right now. The Vue
            // picker treats an empty path as "search unavailable" and simply
            // renders nothing, so a `post` field degrades instead of breaking
            // the page. Point this at the real route once a module owns one.
            'postSearchPath' => '',
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
     *   3. `null` - input renders with a blank placeholder.
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
