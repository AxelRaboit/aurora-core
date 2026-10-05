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
 * **Le dossier d'un client vit sur son espace, pas ici.** Le compte de service
 * appartient à l'installation, et un seul dossier aussi : celui de l'agence,
 * le même pour tous les espaces, que l'équipe consulte depuis n'importe
 * lequel. Brancher le Drive d'un client reste un dossier par client.
 */
enum DriveSettingEnum: string
{
    case Enabled = 'suite_studio_drive_enabled';

    /** La clé JSON telle que Google la livre. Stockée chiffrée. */
    case ServiceAccount = 'suite_studio_drive_service_account';

    /** Le dossier de l'agence, commun à tous les espaces. Facultatif. */
    case AgencyFolder = 'suite_studio_drive_agency_folder';
}
