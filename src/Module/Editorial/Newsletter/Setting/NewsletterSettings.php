<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use DateTimeImmutable;
use SensitiveParameter;

use function in_array;
use function mb_trim;

/**
 * Whether this site may add a visitor to the client's own mailing list, with
 * which provider and which key.
 *
 * Off until the client turns it on with their own Brevo or Mailchimp key:
 * whoever's list an address lands on is who a subscriber's consent was given
 * to, and that is never a key of Axel's.
 */
final readonly class NewsletterSettings
{
    public const array PROVIDERS = ['brevo', 'mailchimp'];

    public function __construct(
        private SettingRepository $settingRepository,
        private EncryptionServiceInterface $encryption,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(NewsletterSettingEnum::Enabled->value)
            && null !== $this->acceptedAt()
            && '' !== $this->apiKey()
            && '' !== $this->listId();
    }

    public function provider(): string
    {
        $stored = (string) $this->settingRepository->get(NewsletterSettingEnum::Provider->value, self::PROVIDERS[0]);

        return in_array($stored, self::PROVIDERS, true) ? $stored : self::PROVIDERS[0];
    }

    public function apiKey(): string
    {
        $stored = (string) $this->settingRepository->get(NewsletterSettingEnum::ApiKey->value, '');

        return '' === $stored ? '' : $this->encryption->decrypt($stored) ?? '';
    }

    public function listId(): string
    {
        return mb_trim((string) $this->settingRepository->get(NewsletterSettingEnum::ListId->value, ''));
    }

    public function acceptedAt(): ?string
    {
        $value = (string) $this->settingRepository->get(NewsletterSettingEnum::TermsAcceptedAt->value, '');

        return '' !== $value ? $value : null;
    }

    public function acceptedBy(): ?string
    {
        $value = (string) $this->settingRepository->get(NewsletterSettingEnum::TermsAcceptedBy->value, '');

        return '' !== $value ? $value : null;
    }

    /** @return array{enabled: bool, provider: string, hasKey: bool, listId: string, acceptedAt: string|null, acceptedBy: string|null} */
    public function state(): array
    {
        return [
            'enabled' => $this->settingRepository->getBoolean(NewsletterSettingEnum::Enabled->value),
            'provider' => $this->provider(),
            'hasKey' => '' !== $this->apiKey(),
            'listId' => $this->listId(),
            'acceptedAt' => $this->acceptedAt(),
            'acceptedBy' => $this->acceptedBy(),
        ];
    }

    public function save(
        bool $enabled,
        bool $termsAccepted,
        string $provider,
        #[SensitiveParameter]
        ?string $apiKey,
        ?string $listId,
        string $acceptedBy,
    ): void {
        $entries = [
            [NewsletterSettingEnum::Provider->value, in_array($provider, self::PROVIDERS, true) ? $provider : self::PROVIDERS[0]],
        ];

        if (null !== $apiKey) {
            $entries[] = [NewsletterSettingEnum::ApiKey->value, '' === $apiKey ? null : $this->encryption->encrypt($apiKey)];
        }

        if (null !== $listId) {
            $entries[] = [NewsletterSettingEnum::ListId->value, '' === mb_trim($listId) ? null : mb_trim($listId)];
        }

        if (!$termsAccepted) {
            $entries[] = [NewsletterSettingEnum::TermsAcceptedAt->value, null];
            $entries[] = [NewsletterSettingEnum::TermsAcceptedBy->value, null];
            $entries[] = [NewsletterSettingEnum::Enabled->value, '0'];
            $this->settingRepository->saveMany($entries);

            return;
        }

        if (null === $this->acceptedAt()) {
            $entries[] = [NewsletterSettingEnum::TermsAcceptedAt->value, new DateTimeImmutable()->format(DateTimeImmutable::ATOM)];
            $entries[] = [NewsletterSettingEnum::TermsAcceptedBy->value, $acceptedBy];
        }

        $entries[] = [NewsletterSettingEnum::Enabled->value, $enabled ? '1' : '0'];
        $this->settingRepository->saveMany($entries);
    }
}
