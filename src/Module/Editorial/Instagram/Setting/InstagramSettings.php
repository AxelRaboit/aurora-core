<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Instagram\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use DateTimeImmutable;
use SensitiveParameter;

use function mb_trim;

/**
 * Whether this site may read its own Instagram feed, and with whose account.
 *
 * Off until the client turns it on with their own long-lived token: Meta
 * holds the account holder responsible for what a page built on its API
 * shows, and that has to be the client, never a shared key of Axel's. The
 * token is encrypted at rest and never sent back to the browser once saved.
 */
final readonly class InstagramSettings
{
    public function __construct(
        private SettingRepository $settingRepository,
        private EncryptionServiceInterface $encryption,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(InstagramSettingEnum::Enabled->value)
            && null !== $this->acceptedAt()
            && '' !== $this->accessToken()
            && '' !== $this->businessAccountId();
    }

    public function accessToken(): string
    {
        $stored = (string) $this->settingRepository->get(InstagramSettingEnum::AccessToken->value, '');

        return '' === $stored ? '' : $this->encryption->decrypt($stored) ?? '';
    }

    public function businessAccountId(): string
    {
        return mb_trim((string) $this->settingRepository->get(InstagramSettingEnum::BusinessAccountId->value, ''));
    }

    public function acceptedAt(): ?string
    {
        $value = (string) $this->settingRepository->get(InstagramSettingEnum::TermsAcceptedAt->value, '');

        return '' !== $value ? $value : null;
    }

    public function acceptedBy(): ?string
    {
        $value = (string) $this->settingRepository->get(InstagramSettingEnum::TermsAcceptedBy->value, '');

        return '' !== $value ? $value : null;
    }

    /** @return array{enabled: bool, hasToken: bool, businessAccountId: string, acceptedAt: string|null, acceptedBy: string|null} */
    public function state(): array
    {
        return [
            'enabled' => $this->settingRepository->getBoolean(InstagramSettingEnum::Enabled->value),
            'hasToken' => '' !== $this->accessToken(),
            'businessAccountId' => $this->businessAccountId(),
            'acceptedAt' => $this->acceptedAt(),
            'acceptedBy' => $this->acceptedBy(),
        ];
    }

    public function save(
        bool $enabled,
        bool $termsAccepted,
        #[SensitiveParameter]
        ?string $accessToken,
        ?string $businessAccountId,
        string $acceptedBy,
    ): void {
        $entries = [];

        if (null !== $accessToken) {
            $entries[] = [InstagramSettingEnum::AccessToken->value, '' === $accessToken ? null : $this->encryption->encrypt($accessToken)];
        }

        if (null !== $businessAccountId) {
            $entries[] = [InstagramSettingEnum::BusinessAccountId->value, '' === mb_trim($businessAccountId) ? null : mb_trim($businessAccountId)];
        }

        if (!$termsAccepted) {
            $entries[] = [InstagramSettingEnum::TermsAcceptedAt->value, null];
            $entries[] = [InstagramSettingEnum::TermsAcceptedBy->value, null];
            $entries[] = [InstagramSettingEnum::Enabled->value, '0'];
            $this->settingRepository->saveMany($entries);

            return;
        }

        if (null === $this->acceptedAt()) {
            $entries[] = [InstagramSettingEnum::TermsAcceptedAt->value, new DateTimeImmutable()->format(DateTimeImmutable::ATOM)];
            $entries[] = [InstagramSettingEnum::TermsAcceptedBy->value, $acceptedBy];
        }

        $entries[] = [InstagramSettingEnum::Enabled->value, $enabled ? '1' : '0'];
        $this->settingRepository->saveMany($entries);
    }
}
