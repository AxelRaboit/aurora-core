<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

use function array_slice;
use function implode;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Quels comptes GitHub la zone « Activité GitHub » affiche.
 *
 * **Éteinte tant que personne ne l'allume**, comme les autres intégrations :
 * Aurora est livrée à des clients, et les comptes affichés sont ceux de la
 * personne qui installe. Rien ne se demande à GitHub avant.
 *
 * Aucune clé : la grille se lit sur la page publique d'un profil, que
 * n'importe quel visiteur peut ouvrir. Il n'y a donc rien de secret ici, et
 * rien à chiffrer.
 */
final readonly class GitHubSettings
{
    /**
     * Au-delà, la zone devient un mur de grilles, et chaque compte est une
     * requête de plus quand le cache se renouvelle.
     */
    public const int MAX_LOGINS = 4;

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    /** Allumée, et au moins un compte à montrer. */
    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(GitHubSettingEnum::Enabled->value)
            && [] !== $this->logins();
    }

    /**
     * Les identifiants enregistrés, dans l'ordre d'affichage.
     *
     * Relus à travers {@see self::parse()} : une ligne écrite à la main en
     * base, ou avant une règle plus stricte, ne doit jamais composer une
     * adresse.
     *
     * @return list<string>
     */
    public function logins(): array
    {
        [$valid] = self::parse((string) $this->settingRepository->get(GitHubSettingEnum::Logins->value, ''));

        return array_slice($valid, 0, self::MAX_LOGINS);
    }

    /**
     * Découpe une saisie libre en identifiants.
     *
     * Une ligne, une virgule ou un espace séparent ; un `@` en tête est
     * toléré, c'est ainsi qu'on écrit un compte partout ailleurs. Les doublons
     * sont ignorés sans casse, comme GitHub les traite.
     *
     * Sans plafond : c'est à qui enregistre de refuser une liste trop longue,
     * et à qui lit de ne jamais en afficher plus que {@see self::MAX_LOGINS}.
     *
     * @return array{0: list<string>, 1: list<string>} les valides, puis les refusés
     */
    public static function parse(string $input): array
    {
        $valid = [];
        $invalid = [];
        $seen = [];

        foreach (preg_split('/[\s,;]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $login = '@' === $token[0] ? mb_substr($token, 1) : $token;

            // Les règles de GitHub : lettres, chiffres et tirets isolés, sans
            // tiret au bord, 39 caractères au plus.
            if (1 !== preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $login)) {
                $invalid[] = $token;

                continue;
            }

            $key = mb_strtolower($login);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $valid[] = $login;
        }

        return [$valid, $invalid];
    }

    /**
     * @return array{enabled: bool, logins: list<string>, max: int}
     */
    public function state(): array
    {
        return [
            'enabled' => $this->settingRepository->getBoolean(GitHubSettingEnum::Enabled->value),
            'logins' => $this->logins(),
            'max' => self::MAX_LOGINS,
        ];
    }

    /**
     * @param list<string> $logins déjà passés par {@see self::parse()}
     */
    public function save(bool $enabled, array $logins): void
    {
        $this->settingRepository->saveMany([
            [GitHubSettingEnum::Logins->value, [] === $logins ? null : implode("\n", $logins)],
            [GitHubSettingEnum::Enabled->value, $enabled ? '1' : '0'],
        ]);
    }
}
