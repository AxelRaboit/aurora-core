<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

/**
 * Les deux lignes que l'intégration GitHub garde dans la table des réglages.
 *
 * Pas un `ApplicationParameterEnumInterface` : l'onglet se dessine lui-même,
 * comme ceux de Pexels et de Craft, parce qu'une liste de comptes se vérifie
 * avant d'être enregistrée et que l'écran générique n'a pas de quoi le faire.
 * {@see GitHubSettings} est la seule porte d'entrée.
 */
enum GitHubSettingEnum: string
{
    case Enabled = 'backend_editorial_github_enabled';

    /** Les identifiants, un par ligne, dans l'ordre d'affichage. */
    case Logins = 'backend_editorial_github_logins';
}
