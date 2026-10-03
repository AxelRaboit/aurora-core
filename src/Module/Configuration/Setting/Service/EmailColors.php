<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;

use function hexdec;
use function implode;
use function is_string;
use function mb_str_split;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function round;
use function sprintf;
use function vsprintf;

/**
 * Les couleurs des e-mails, réglées dans Réglages > Emails.
 *
 * Les défauts sont les couleurs que `email.css` code en dur : tant qu'un
 * réglage n'en change aucune, `css()` est vide et un e-mail sort à l'octet près
 * comme avant. L'accent suit par défaut la couleur principale du thème actif ;
 * un thème qui n'en pose pas laisse le vert d'origine. Seul un hexadécimal à six chiffres passe, la valeur finit dans
 * le HTML du message.
 */
final readonly class EmailColors
{
    /** Le départ du dégradé de la pastille quand l'accent est celui d'origine. */
    private const string DEFAULT_ACCENT_LIGHT = '#10b981';

    private const string HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(
        private SettingRepository $settingRepository,
        private ThemeContext $themeContext,
    ) {}

    /**
     * @return array{accent: string, accentLight: string, background: string, heading: string, text: string}
     */
    public function colors(): array
    {
        $accent = $this->accent();

        return [
            'accent' => $accent,
            'accentLight' => $accent === ApplicationParameterEnum::EmailAccentColor->getDefaultValue()
                ? self::DEFAULT_ACCENT_LIGHT
                : $this->towardWhite($accent, 0.25),
            'background' => $this->read(ApplicationParameterEnum::EmailBackgroundColor),
            'heading' => $this->read(ApplicationParameterEnum::EmailHeadingColor),
            'text' => $this->read(ApplicationParameterEnum::EmailTextColor),
        ];
    }

    /**
     * Les règles qui repeignent `email.css`, mêmes sélecteurs et posées après
     * lui : l'inliner les fait gagner à spécificité égale.
     */
    public function css(): string
    {
        $colors = $this->colors();
        $rules = [];

        if ($this->changed(ApplicationParameterEnum::EmailAccentColor, $colors['accent'])) {
            $rules[] = sprintf('a,.header a,.fallback a{color:%1$s}.button-primary{background-color:%1$s}.panel{border-left-color:%1$s}', $colors['accent']);
        }

        if ($this->changed(ApplicationParameterEnum::EmailBackgroundColor, $colors['background'])) {
            $rules[] = sprintf('body,.wrapper,.panel-content{background-color:%1$s}.body{background-color:%1$s;border-bottom-color:%1$s;border-top-color:%1$s}', $colors['background']);
        }

        if ($this->changed(ApplicationParameterEnum::EmailHeadingColor, $colors['heading'])) {
            $rules[] = sprintf('h1{color:%s}', $colors['heading']);
        }

        if ($this->changed(ApplicationParameterEnum::EmailTextColor, $colors['text'])) {
            $rules[] = sprintf('body,.panel-content{color:%s}', $colors['text']);
        }

        return implode('', $rules);
    }

    private function accent(): string
    {
        $follows = ApplicationParameterEnum::EmailAccentFollowsTheme;
        if ('1' !== $this->settingRepository->get($follows->value, $follows->getDefaultValue())) {
            return $this->read(ApplicationParameterEnum::EmailAccentColor);
        }

        $themeColor = $this->themeContext->activeTheme()?->getConfig()['primary_color'] ?? null;

        return is_string($themeColor) && 1 === preg_match(self::HEX_PATTERN, $themeColor)
            ? mb_strtolower($themeColor)
            : ApplicationParameterEnum::EmailAccentColor->getDefaultValue();
    }

    private function read(ApplicationParameterEnum $parameter): string
    {
        $value = $this->settingRepository->get($parameter->value, $parameter->getDefaultValue());

        return null !== $value && 1 === preg_match(self::HEX_PATTERN, $value)
            ? mb_strtolower($value)
            : $parameter->getDefaultValue();
    }

    private function changed(ApplicationParameterEnum $parameter, string $color): bool
    {
        return $color !== $parameter->getDefaultValue();
    }

    /** Un hexadécimal mélangé au blanc, en hexadécimal : les messageries ne lisent pas `color-mix`. */
    private function towardWhite(string $hex, float $share): string
    {
        $channels = [];
        foreach (mb_str_split(mb_substr($hex, 1), 2) as $channel) {
            $value = (int) hexdec($channel);
            $channels[] = (int) round($value + (255 - $value) * $share);
        }

        return vsprintf('#%02x%02x%02x', $channels);
    }
}
