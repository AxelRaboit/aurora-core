<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GoogleReviews\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use DateTimeImmutable;
use SensitiveParameter;

use function mb_trim;

/**
 * Whether this site may show its own Google reviews, and with whose key.
 *
 * Off until the client turns it on with a Places API key from their own
 * Google Cloud project: Google bills and rate-limits by key, and a shared
 * one would mean one client's traffic spending another's quota.
 */
final readonly class GoogleReviewsSettings
{
    public function __construct(
        private SettingRepository $settingRepository,
        private EncryptionServiceInterface $encryption,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(GoogleReviewsSettingEnum::Enabled->value)
            && null !== $this->acceptedAt()
            && '' !== $this->apiKey()
            && '' !== $this->placeId();
    }

    public function apiKey(): string
    {
        $stored = (string) $this->settingRepository->get(GoogleReviewsSettingEnum::ApiKey->value, '');

        return '' === $stored ? '' : $this->encryption->decrypt($stored) ?? '';
    }

    public function placeId(): string
    {
        return mb_trim((string) $this->settingRepository->get(GoogleReviewsSettingEnum::PlaceId->value, ''));
    }

    public function acceptedAt(): ?string
    {
        $value = (string) $this->settingRepository->get(GoogleReviewsSettingEnum::TermsAcceptedAt->value, '');

        return '' !== $value ? $value : null;
    }

    public function acceptedBy(): ?string
    {
        $value = (string) $this->settingRepository->get(GoogleReviewsSettingEnum::TermsAcceptedBy->value, '');

        return '' !== $value ? $value : null;
    }

    /** @return array{enabled: bool, hasKey: bool, placeId: string, acceptedAt: string|null, acceptedBy: string|null} */
    public function state(): array
    {
        return [
            'enabled' => $this->settingRepository->getBoolean(GoogleReviewsSettingEnum::Enabled->value),
            'hasKey' => '' !== $this->apiKey(),
            'placeId' => $this->placeId(),
            'acceptedAt' => $this->acceptedAt(),
            'acceptedBy' => $this->acceptedBy(),
        ];
    }

    public function save(
        bool $enabled,
        bool $termsAccepted,
        #[SensitiveParameter]
        ?string $apiKey,
        ?string $placeId,
        string $acceptedBy,
    ): void {
        $entries = [];

        if (null !== $apiKey) {
            $entries[] = [GoogleReviewsSettingEnum::ApiKey->value, '' === $apiKey ? null : $this->encryption->encrypt($apiKey)];
        }

        if (null !== $placeId) {
            $entries[] = [GoogleReviewsSettingEnum::PlaceId->value, '' === mb_trim($placeId) ? null : mb_trim($placeId)];
        }

        if (!$termsAccepted) {
            $entries[] = [GoogleReviewsSettingEnum::TermsAcceptedAt->value, null];
            $entries[] = [GoogleReviewsSettingEnum::TermsAcceptedBy->value, null];
            $entries[] = [GoogleReviewsSettingEnum::Enabled->value, '0'];
            $this->settingRepository->saveMany($entries);

            return;
        }

        if (null === $this->acceptedAt()) {
            $entries[] = [GoogleReviewsSettingEnum::TermsAcceptedAt->value, new DateTimeImmutable()->format(DateTimeImmutable::ATOM)];
            $entries[] = [GoogleReviewsSettingEnum::TermsAcceptedBy->value, $acceptedBy];
        }

        $entries[] = [GoogleReviewsSettingEnum::Enabled->value, $enabled ? '1' : '0'];
        $this->settingRepository->saveMany($entries);
    }
}
