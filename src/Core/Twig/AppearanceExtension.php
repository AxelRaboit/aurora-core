<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\EmailColors;
use Aurora\Module\Configuration\Setting\Service\SuitePalette;
use JsonException;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

use const JSON_THROW_ON_ERROR;

/**
 * Exposes Appearance application parameters to Twig so they can be injected
 * into the page (`window.__auroraConfig`) and consumed by Vue components
 * without an extra AJAX round-trip at mount time.
 *
 * The values are read once per request from the SettingRepository (itself
 * cached in-memory), and decoded on demand so the layout call is cheap.
 * Reset between two messages of a worker, like the repository: an e-mail sent
 * after a change of setting must not carry the colours read before it.
 */
final class AppearanceExtension implements ResetInterface
{
    /** @var list<string>|null */
    private ?array $cachedColorPickerPresets = null;

    /** @var array<string, array{family: string, overrides: array<string, string>}>|null */
    private ?array $cachedSuitePalette = null;

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly EmailColors $emailColors,
    ) {}

    public function reset(): void
    {
        $this->cachedColorPickerPresets = null;
        $this->cachedSuitePalette = null;
    }

    /**
     * The e-mail colours, for the values written into the template's HTML
     * (logo badge, button).
     *
     * @return array{accent: string, accentLight: string, background: string, heading: string, text: string}
     */
    #[AsTwigFunction(name: 'app_email_colors')]
    public function getEmailColors(): array
    {
        return $this->emailColors->colors();
    }

    /** The rules added to `email.css` before inlining, empty by default. */
    #[AsTwigFunction(name: 'app_email_css')]
    public function getEmailCss(): string
    {
        return $this->emailColors->css();
    }

    /**
     * Returns the configured color picker preset palette, falling back to the
     * built-in default if the setting is unset, blank or malformed.
     *
     * @return list<string>
     */
    #[AsTwigFunction(name: 'app_color_presets')]
    public function getColorPickerPresets(): array
    {
        if (null !== $this->cachedColorPickerPresets) {
            return $this->cachedColorPickerPresets;
        }

        $raw = $this->settingRepository->get(
            ApplicationParameterEnum::ColorPickerPresets->value,
            ApplicationParameterEnum::ColorPickerPresets->getDefaultValue(),
        );

        $presets = $this->parsePresets($raw);
        if ([] === $presets) {
            $presets = ApplicationParameterEnum::DEFAULT_COLOR_PICKER_PRESETS;
        }

        return $this->cachedColorPickerPresets = $presets;
    }

    /**
     * The greys of the back office and the client space, empty as long as
     * they are those of `theme.css`.
     */
    #[AsTwigFunction(name: 'app_suite_palette_css')]
    public function getSuitePaletteCss(): string
    {
        return SuitePalette::css($this->suitePalette());
    }

    /**
     * What the Appearance tab needs to know to compose and preview the palette
     * without asking the server for the families again.
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction(name: 'app_suite_palette')]
    public function getSuitePalette(): array
    {
        return [
            'value' => $this->suitePalette(),
            'families' => SuitePalette::FAMILIES,
            'defaultFamily' => SuitePalette::DEFAULT_FAMILY,
            'tokens' => array_map(static fn (array $definition): array => ['light' => $definition[1], 'dark' => $definition[2]], SuitePalette::TOKENS),
            'states' => array_map(static fn (array $definition): array => ['light' => $definition[1], 'dark' => $definition[2]], SuitePalette::STATE_TOKENS),
        ];
    }

    /**
     * @return array<string, array{family: string, overrides: array<string, string>}>
     */
    private function suitePalette(): array
    {
        return $this->cachedSuitePalette ??= SuitePalette::fromStored(
            $this->settingRepository->get(
                ApplicationParameterEnum::SuitePalette->value,
                ApplicationParameterEnum::SuitePalette->getDefaultValue(),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function parsePresets(?string $raw): array
    {
        if (null === $raw || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $valid = [];
        foreach ($decoded as $hex) {
            if (is_string($hex) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                $valid[] = $hex;
            }
        }

        return $valid;
    }
}
