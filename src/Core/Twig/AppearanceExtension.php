<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\BackendPalette;
use Aurora\Module\Configuration\Setting\Service\EmailColors;
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
    private ?array $cachedBackendPalette = null;

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly EmailColors $emailColors,
    ) {}

    public function reset(): void
    {
        $this->cachedColorPickerPresets = null;
        $this->cachedBackendPalette = null;
    }

    /**
     * Les couleurs des e-mails, pour les valeurs écrites dans le HTML du
     * gabarit (pastille du logo, bouton).
     *
     * @return array{accent: string, accentLight: string, background: string, heading: string, text: string}
     */
    #[AsTwigFunction(name: 'app_email_colors')]
    public function getEmailColors(): array
    {
        return $this->emailColors->colors();
    }

    /** Les règles ajoutées à `email.css` avant l'inlining, vide au défaut. */
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
     * Les gris du back-office et de l'espace client, vide tant qu'ils sont
     * ceux de `theme.css`.
     */
    #[AsTwigFunction(name: 'app_backend_palette_css')]
    public function getBackendPaletteCss(): string
    {
        return BackendPalette::css($this->backendPalette());
    }

    /**
     * Ce que l'onglet Apparence doit connaître pour composer et prévisualiser
     * la palette sans redemander les familles au serveur.
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction(name: 'app_backend_palette')]
    public function getBackendPalette(): array
    {
        return [
            'value' => $this->backendPalette(),
            'families' => BackendPalette::FAMILIES,
            'defaultFamily' => BackendPalette::DEFAULT_FAMILY,
            'tokens' => array_map(static fn (array $definition): array => ['light' => $definition[1], 'dark' => $definition[2]], BackendPalette::TOKENS),
            'states' => array_map(static fn (array $definition): array => ['light' => $definition[1], 'dark' => $definition[2]], BackendPalette::STATE_TOKENS),
        ];
    }

    /**
     * @return array<string, array{family: string, overrides: array<string, string>}>
     */
    private function backendPalette(): array
    {
        return $this->cachedBackendPalette ??= BackendPalette::fromStored(
            $this->settingRepository->get(
                ApplicationParameterEnum::BackendPalette->value,
                ApplicationParameterEnum::BackendPalette->getDefaultValue(),
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
