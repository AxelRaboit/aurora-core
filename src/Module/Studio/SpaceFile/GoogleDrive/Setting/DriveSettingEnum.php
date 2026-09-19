<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * Les deux lignes que l'intégration Drive garde dans la table des réglages.
 *
 * Volontairement pas un {@see ApplicationParameterEnumInterface} : cette
 * interface existe pour que l'écran générique dessine un champ et renvoie sa
 * valeur au navigateur, et la clé d'un compte de service n'a rien à faire dans
 * le source d'une page.
 *
 * **Deux lignes seulement, et pas de dossier ici.** Le compte de service
 * appartient à l'installation ; le dossier appartient à un espace, et vit donc
 * sur l'espace. Brancher un Drive, c'est désigner un dossier par client, pas
 * un pour tout le monde.
 */
enum DriveSettingEnum: string
{
    case Enabled = 'backend_studio_drive_enabled';

    /** La clé JSON telle que Google la livre. Stockée chiffrée. */
    case ServiceAccount = 'backend_studio_drive_service_account';
}
