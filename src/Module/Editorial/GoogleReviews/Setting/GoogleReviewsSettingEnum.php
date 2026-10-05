<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GoogleReviews\Setting;

enum GoogleReviewsSettingEnum: string
{
    case Enabled = 'suite_editorial_google_reviews_enabled';

    /** A Places API key, scoped to Place Details by the client in their own Google Cloud console. Stored encrypted. */
    case ApiKey = 'suite_editorial_google_reviews_api_key';

    /** The Google Place id of the client's own business listing. */
    case PlaceId = 'suite_editorial_google_reviews_place_id';

    case TermsAcceptedAt = 'suite_editorial_google_reviews_terms_accepted_at';

    case TermsAcceptedBy = 'suite_editorial_google_reviews_terms_accepted_by';
}
