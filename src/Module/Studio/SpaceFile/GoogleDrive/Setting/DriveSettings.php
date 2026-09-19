<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use SensitiveParameter;

use function mb_trim;

/**
 * Si cette installation peut lire un Drive, et avec quel compte.
 *
 * **Éteinte tant que personne ne l'allume**, comme les autres intégrations.
 *
 * **Un seul compte de service pour l'installation.** Chaque espace désigne
 * ensuite son dossier, et c'est le client qui partage ce dossier avec
 * l'adresse du compte : la portée se décide dans Drive, pas ici. Un compte par
 * espace obligerait à créer un projet Google par client pour ne rien gagner -
 * un compte qui n'a reçu aucun partage ne voit rien de toute façon.
 *
 * La clé est chiffrée au repos avec {@see EncryptionServiceInterface}. Elle
 * pèse plus lourd qu'un jeton et vaut plus cher : elle contient une clé privée
 * RSA, et qui la tient peut lire tout ce que le compte s'est vu partager.
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
     * Le compte de service, prêt à signer.
     *
     * Null quand rien n'est enregistré, quand le déchiffrement échoue - une
     * clé écrite avant une rotation - ou quand le contenu n'est pas une clé de
     * compte de service. Les trois cas se réparent au même endroit, l'onglet
     * des réglages, et l'intégration se tait en attendant.
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
     * Ce que l'écran de réglages a le droit de savoir.
     *
     * L'adresse du compte, et pas la clé : c'est celle que le client doit
     * recopier dans le partage de son dossier, donc elle doit être affichable
     * - et c'est la seule partie de la clé qui n'est pas un secret.
     *
     * @return array{enabled: bool, hasAccount: bool, email: string|null}
     */
    public function state(): array
    {
        $account = $this->account();

        return [
            'enabled' => $this->settingRepository->getBoolean(DriveSettingEnum::Enabled->value),
            'hasAccount' => $account instanceof GoogleServiceAccount,
            'email' => $account?->email,
        ];
    }

    /**
     * @param string|null $serviceAccount null laisse la clé enregistrée
     *                                    tranquille ; une chaîne vide est un
     *                                    « oublie-la » explicite
     */
    public function save(
        bool $enabled,
        #[SensitiveParameter]
        ?string $serviceAccount,
    ): void {
        $entries = [];

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
