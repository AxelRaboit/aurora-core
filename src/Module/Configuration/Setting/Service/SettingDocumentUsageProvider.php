<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Aurora\Module\Ged\Document\Contract\TypedDocumentUsageProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_flip;

/**
 * Says which site settings draw a picture: the logo, the favicon, the default
 * image shared on social networks.
 *
 * They hold a document id like any post does, but nothing reported them, so
 * the favicon of the site read "Inutilisé" in the library and could be thrown
 * away with nothing to warn against it.
 */
final readonly class SettingDocumentUsageProvider implements BatchDocumentUsageProviderInterface, TypedDocumentUsageProviderInterface
{
    /** The settings whose value is a document id. */
    private const array MEDIA_SETTINGS = [
        ApplicationParameterEnum::LogoMediaId,
        ApplicationParameterEnum::FaviconMediaId,
        ApplicationParameterEnum::SeoDefaultOgImage,
    ];

    public function __construct(
        private SettingRepository $settings,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    public function usageType(): string
    {
        return 'configuration.setting';
    }

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $usages = [];

        foreach (self::MEDIA_SETTINGS as $setting) {
            if ($this->documentIdOf($setting) !== $documentId) {
                continue;
            }

            $usages[] = [
                'type' => $this->usageType(),
                'label' => $this->translator->trans($setting->getLabel()),
                'detail' => $this->translator->trans('suite.settings.usage_detail'),
                'href' => $this->urlGenerator->generate('suite_configuration_settings_tab', ['tab' => $setting->getGroup()]),
            ];
        }

        return $usages;
    }

    /**
     * Three settings, read from the repository's cache: nothing to batch, the
     * page of documents is matched against them in memory.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesFor(array $documentIds): array
    {
        $asked = array_flip($documentIds);
        $counts = [];

        foreach (self::MEDIA_SETTINGS as $setting) {
            $id = $this->documentIdOf($setting);
            if (null !== $id && isset($asked[$id])) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function documentIdOf(ApplicationParameterEnum $setting): ?int
    {
        $id = (int) $this->settings->get($setting->value, '');

        return $id > 0 ? $id : null;
    }
}
