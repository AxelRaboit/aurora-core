<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Craft\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use SensitiveParameter;

use function mb_rtrim;
use function mb_trim;
use function str_starts_with;

/**
 * Si cette installation peut lire un espace Craft, et lequel.
 *
 * **Éteinte tant que personne ne l'allume**, comme les autres intégrations :
 * Aurora est livrée à des clients, et le compte derrière le jeton est celui de
 * la personne qui installe, pas le mien.
 *
 * **La connexion ne porte que les documents choisis.** Craft propose les deux :
 * tout l'espace, ou une sélection. Une installation d'Aurora vit sur un serveur
 * loué, et un jeton qui y dort ne doit pas ouvrir l'intégralité d'un savoir
 * personnel pour qu'un brief atterrisse dans un espace client. C'est donc dans
 * Craft qu'on désigne ce qu'Aurora a le droit de voir, et l'écran d'import ne
 * montre rien d'autre.
 *
 * Le jeton est chiffré au repos avec {@see EncryptionServiceInterface}, comme
 * les mots de passe des points de montage : un dump de base reste un dump de
 * base.
 */
final readonly class CraftSettings
{
    public function __construct(
        private SettingRepository $settingRepository,
        private EncryptionServiceInterface $encryption,
    ) {}

    /**
     * La seule question que le reste du code pose.
     *
     * Les trois conditions à chaque fois : une adresse sans jeton ne répond
     * rien, un jeton sans adresse ne va nulle part.
     */
    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(CraftSettingEnum::Enabled->value)
            && '' !== $this->endpoint()
            && '' !== $this->token();
    }

    /**
     * L'adresse, sans barre oblique finale.
     *
     * Craft la donne telle quelle et les chemins s'y ajoutent : la normaliser
     * ici évite le `//documents` qu'une adresse recopiée avec sa barre produit
     * une fois sur deux.
     */
    public function endpoint(): string
    {
        $stored = mb_trim((string) $this->settingRepository->get(CraftSettingEnum::Endpoint->value, ''));

        if ('' === $stored || !str_starts_with($stored, 'https://')) {
            // Une adresse en clair refusée plutôt que corrigée : un jeton
            // parti en HTTP est un jeton lu par qui tient le réseau.
            return '';
        }

        return mb_rtrim($stored, '/');
    }

    public function token(): string
    {
        $stored = (string) $this->settingRepository->get(CraftSettingEnum::Token->value, '');

        if ('' === $stored) {
            return '';
        }

        // Un jeton écrit avant une rotation de la clé de chiffrement se
        // déchiffre en null. Traité comme absent plutôt que fatal :
        // l'intégration se tait et l'onglet redemande le jeton, qui est la
        // seule chose qui répare.
        return $this->encryption->decrypt($stored) ?? '';
    }

    /**
     * Ce que l'écran de réglages a le droit de savoir.
     *
     * `hasToken` et non le jeton : le navigateur doit dessiner « un jeton est
     * enregistré », rien de plus.
     *
     * @return array{enabled: bool, endpoint: string, hasToken: bool}
     */
    public function state(): array
    {
        return [
            'enabled' => $this->settingRepository->getBoolean(CraftSettingEnum::Enabled->value),
            'endpoint' => $this->endpoint(),
            'hasToken' => '' !== $this->token(),
        ];
    }

    /**
     * @param string|null $token null laisse le jeton enregistré tranquille -
     *                           c'est ainsi qu'un formulaire qui ne l'a jamais
     *                           reçu enregistre le reste de l'onglet. Une
     *                           chaîne vide est un « oublie-le » explicite.
     */
    public function save(
        bool $enabled,
        string $endpoint,
        #[SensitiveParameter]
        ?string $token,
    ): void {
        $entries = [[CraftSettingEnum::Endpoint->value, '' === mb_trim($endpoint) ? null : mb_trim($endpoint)]];

        if (null !== $token) {
            $entries[] = [
                CraftSettingEnum::Token->value,
                '' === $token ? null : $this->encryption->encrypt($token),
            ];
        }

        $entries[] = [CraftSettingEnum::Enabled->value, $enabled ? '1' : '0'];

        $this->settingRepository->saveMany($entries);
    }
}
