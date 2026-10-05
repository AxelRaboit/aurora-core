<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class SiteBrandingExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    public function getGlobals(): array
    {
        return [
            'siteName' => $this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteName),
            'siteDescription' => $this->settingRepository->get(
                ApplicationParameterEnum::SiteDescription->value,
                ApplicationParameterEnum::SiteDescription->getDefaultValue(),
            ) ?? '',
            'siteLogoUrl' => $this->resolveMediaUrl(ApplicationParameterEnum::LogoMediaId),
            'siteFaviconUrl' => $this->resolveMediaUrl(ApplicationParameterEnum::FaviconMediaId),
            'siteNameOnPhone' => $this->settingRepository->getBoolean(ApplicationParameterEnum::SuiteBarSiteNameOnPhone->value, true),
        ];
    }

    private function resolveMediaUrl(ApplicationParameterEnum $parameter): ?string
    {
        $rawId = $this->settingRepository->get($parameter->value, '');
        if (null === $rawId || '' === $rawId) {
            return null;
        }

        $documentId = (int) $rawId;
        if ($documentId <= 0) {
            return null;
        }

        $document = $this->documentRepository->find($documentId);

        return $this->documentUrlGenerator->publicUrl($document);
    }
}
