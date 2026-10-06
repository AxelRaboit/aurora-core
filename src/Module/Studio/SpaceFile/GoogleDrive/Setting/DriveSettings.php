<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use SensitiveParameter;

use function mb_trim;

/**
 * Whether this installation can read a Drive, and with which account.
 *
 * **Off until someone switches it on**, like the other integrations.
 *
 * **A single service account for the installation.** Each space then
 * designates its folder, and the client shares that folder with the
 * account's address: the scope is decided in Drive, not here. One account per
 * space would require a Google project per client to gain nothing - an
 * account nothing was shared with sees nothing anyway.
 *
 * The key is encrypted at rest with {@see EncryptionServiceInterface}. It
 * weighs more than a token and is worth more: it holds an RSA private key,
 * and whoever holds it can read everything shared with the account.
 */
final readonly class DriveSettings
{
    public function __construct(
        private SettingRepository $settingRepository,
        private EncryptionServiceInterface $encryption,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(DriveSettingEnum::Enabled->value)
            && $this->account() instanceof GoogleServiceAccount;
    }

    /**
     * The service account, ready to sign.
     *
     * Null when nothing is stored, when decryption fails - a key written before
     * a rotation - or when the content is not a service account key. All three
     * cases are fixed in the same place, the settings tab, and the integration
     * stays quiet meanwhile.
     */
    public function account(): ?GoogleServiceAccount
    {
        $stored = (string) $this->settingRepository->get(DriveSettingEnum::ServiceAccount->value, '');

        if ('' === $stored) {
            return null;
        }

        $json = $this->encryption->decrypt($stored);

        return null === $json ? null : GoogleServiceAccount::fromJson($json);
    }

    /**
     * What the settings screen is allowed to know.
     *
     * The account's address, and not the key: it is the one the client must
     * copy into their folder's sharing, so it must be displayable - and it is
     * the only part of the key that is not a secret.
     *
     * @return array{enabled: bool, hasAccount: bool, email: string|null}
     */
    /**
     * The agency's own folder: shared resources the team reads from every
     * space - templates, guidelines. Never shown to a client. Null while
     * none is set.
     */
    public function agencyFolderId(): ?string
    {
        $stored = (string) $this->settingRepository->get(DriveSettingEnum::AgencyFolder->value, '');

        return '' === $stored ? null : $stored;
    }

    public function state(): array
    {
        $account = $this->account();

        return [
            'enabled' => $this->settingRepository->getBoolean(DriveSettingEnum::Enabled->value),
            'hasAccount' => $account instanceof GoogleServiceAccount,
            'email' => $account?->email,
            'agencyFolderId' => $this->agencyFolderId(),
        ];
    }

    /**
     * @param string|null $agencyFolderId null leaves it as it is, an empty
     *                                    string clears it
     */
    /** The agency folder and nothing else; an empty string clears it. */
    public function saveAgencyFolder(string $agencyFolderId): void
    {
        $this->settingRepository->saveMany([[DriveSettingEnum::AgencyFolder->value, '' === $agencyFolderId ? null : $agencyFolderId]]);
    }

    public function save(
        bool $enabled,
        #[SensitiveParameter]
        ?string $serviceAccount,
        ?string $agencyFolderId = null,
    ): void {
        $entries = [];

        if (null !== $agencyFolderId) {
            $entries[] = [DriveSettingEnum::AgencyFolder->value, '' === $agencyFolderId ? null : $agencyFolderId];
        }

        if (null !== $serviceAccount) {
            $json = mb_trim($serviceAccount);

            $entries[] = [
                DriveSettingEnum::ServiceAccount->value,
                '' === $json ? null : $this->encryption->encrypt($json),
            ];
        }

        $entries[] = [DriveSettingEnum::Enabled->value, $enabled ? '1' : '0'];

        $this->settingRepository->saveMany($entries);
    }
}
