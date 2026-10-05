<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Pexels\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * The four rows the Pexels integration keeps in the settings table.
 *
 * Deliberately not an {@see ApplicationParameterEnumInterface}:
 * that interface exists so the generic settings screen can draw a field and
 * ship its current value to the browser, and neither is wanted here. An API
 * key has no business being echoed into a page, and an acceptance date is a
 * record of something a person did rather than a value they may retype.
 *
 * So the tab draws itself, and {@see PexelsSettings} is the only way in.
 */
enum PexelsSettingEnum: string
{
    case Enabled = 'suite_ged_pexels_enabled';

    /** Stored encrypted; see {@see PexelsSettings}. */
    case ApiKey = 'suite_ged_pexels_api_key';

    case TermsAcceptedAt = 'suite_ged_pexels_terms_accepted_at';

    case TermsAcceptedBy = 'suite_ged_pexels_terms_accepted_by';
}
