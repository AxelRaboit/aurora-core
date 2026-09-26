<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Instagram\Setting;

use Aurora\Module\Ged\Pexels\Setting\PexelsSettingEnum;

/**
 * The rows the Instagram tab keeps.
 *
 * Not an `ApplicationParameterEnumInterface`, for the reason {@see PexelsSettingEnum}
 * gives: a token has nothing to do in the source of a page, so the tab draws
 * itself and {@see InstagramSettings} is the only door in.
 */
enum InstagramSettingEnum: string
{
    case Enabled = 'backend_editorial_instagram_enabled';

    /** A long-lived Instagram Graph API token, for the client's own account. Stored encrypted. */
    case AccessToken = 'backend_editorial_instagram_access_token';

    /** The Instagram Business Account id the token reads. */
    case BusinessAccountId = 'backend_editorial_instagram_business_account_id';

    case TermsAcceptedAt = 'backend_editorial_instagram_terms_accepted_at';

    case TermsAcceptedBy = 'backend_editorial_instagram_terms_accepted_by';
}
