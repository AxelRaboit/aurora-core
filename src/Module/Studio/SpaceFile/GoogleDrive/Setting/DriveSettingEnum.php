<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * The two rows the Drive integration keeps in the settings table.
 *
 * Deliberately not an {@see ApplicationParameterEnumInterface}: that interface
 * exists so the generic screen draws a field and sends its value back to the
 * browser, and a service account key has no business in a page's source.
 *
 * **A client's folder lives on their space, not here.** The service account
 * belongs to the installation, and so does a single folder: the agency's,
 * the same for every space, which the team browses from any of them.
 * Connecting a client's Drive stays one folder per client.
 */
enum DriveSettingEnum: string
{
    case Enabled = 'suite_studio_drive_enabled';

    /** The JSON key as Google delivers it. Stored encrypted. */
    case ServiceAccount = 'suite_studio_drive_service_account';

    /** The agency's folder, shared by every space. Optional. */
    case AgencyFolder = 'suite_studio_drive_agency_folder';
}
