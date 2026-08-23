<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\Enum;

use Symfony\Component\Intl\Countries;

/**
 * ISO 3166-1 alpha-2 country codes the application currently supports for shipping and billing.
 *
 * Distinct from {@see LocaleEnum} - a locale is a language tag (fr, en, es, de),
 * a country is a territory (FR, BE, CH, …). A user can be in fr_BE.
 *
 * Add cases as needed when expanding to new markets. Display names come from Symfony Intl
 * (CLDR data) so they are translated automatically per active locale.
 */
enum CountryEnum: string
{
    case France = 'FR';
    case Belgium = 'BE';
    case Switzerland = 'CH';
    case Canada = 'CA';
    case Germany = 'DE';
    case Spain = 'ES';
    case UnitedKingdom = 'GB';
    case UnitedStates = 'US';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::France;
    }

    /** Localized display name (e.g. "France", "Allemagne", "Germany"). */
    public function label(string $locale = 'fr'): string
    {
        return Countries::getName($this->value, $locale);
    }

    /**
     * Returns the supported countries as `[code => label]`, sorted alphabetically by label
     * in the given locale. Ready to feed an HTML <select>.
     *
     * @return array<string, string>
     */
    public static function options(string $locale = 'fr'): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label($locale);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
