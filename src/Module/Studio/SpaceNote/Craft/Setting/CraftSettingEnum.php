<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Craft\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * Les trois lignes que l'intégration Craft garde dans la table des réglages.
 *
 * Volontairement pas un {@see ApplicationParameterEnumInterface}, pour la même
 * raison que Pexels : cette interface existe pour que l'écran générique dessine
 * un champ et envoie sa valeur au navigateur, et un jeton n'a rien à faire
 * dans le source d'une page. L'onglet se dessine donc lui-même, et
 * {@see CraftSettings} est la seule porte d'entrée.
 */
enum CraftSettingEnum: string
{
    case Enabled = 'backend_studio_craft_enabled';

    /** L'adresse que Craft donne à la création d'une connexion. */
    case Endpoint = 'backend_studio_craft_endpoint';

    /** Stocké chiffré ; voir {@see CraftSettings}. */
    case Token = 'backend_studio_craft_token';
}
