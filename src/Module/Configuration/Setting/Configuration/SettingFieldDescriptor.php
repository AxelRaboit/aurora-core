<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Configuration;

/**
 * One row in the admin Settings page. Identifies the persisted key, its type
 * (steering the Vue renderer + suite casting), and the i18n keys used for
 * label / help text. Each tab returned by a {@see ConfigurationTabProviderInterface}
 * carries a list of these.
 *
 * Kept non-final so a client can extend in edge cases (e.g. exotic
 * type-specific metadata), though most contributions should just construct
 * one as-is.
 *
 * @phpstan-type SelectOption array{value: string, label: string}
 */
class SettingFieldDescriptor
{
    /**
     * @param list<SelectOption>|null $options Concrete choice list for `select`/`multiselect` types
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $labelKey,
        public readonly string $descriptionKey,
        public readonly string $defaultValue,
        public readonly ?array $options = null,
        /**
         * Optional translation key for the input placeholder - a concrete
         * example hint shown inside the input (e.g. "INV-2026-000042" for
         * a reference prefix, "admin@example.com" for an email recipient).
         *
         * Leave null when the field has no useful sample value. The label
         * sits above the input and the description renders below, so an
         * empty placeholder is fine - only set this when an example is
         * genuinely clearer than the description alone.
         */
        public readonly ?string $placeholderKey = null,
        /**
         * Optional translation key for a warning shown before a `bool` field
         * is switched **off**.
         *
         * For the settings whose "off" is a decision rather than a
         * preference: turning one of those off should say what it costs,
         * once, in front of the person doing it - not in a changelog they
         * will read afterwards.
         *
         * Null for everything else, which is nearly everything: a
         * confirmation on an ordinary toggle is a click people learn to
         * dismiss, and that is how the ones that matter stop being read.
         */
        public readonly ?string $offWarningKey = null,
        /**
         * The section the field belongs to inside its tab, or null. Sections
         * are drawn as titled blocks, the field cards beside their title
         * and the sentence that says what they are for; a tab whose fields
         * have none draws one card, as before. Its title and lead are
         * `suite.settings.sections.<key>.title|lead`.
         */
        public readonly ?string $sectionKey = null,
    ) {}
}
