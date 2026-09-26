<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Setting;

enum NewsletterSettingEnum: string
{
    case Enabled = 'backend_editorial_newsletter_enabled';

    /** Which provider the key below belongs to: 'brevo' or 'mailchimp'. */
    case Provider = 'backend_editorial_newsletter_provider';

    /** Stored encrypted. */
    case ApiKey = 'backend_editorial_newsletter_api_key';

    /** Brevo's numeric list id, or Mailchimp's audience id. */
    case ListId = 'backend_editorial_newsletter_list_id';

    case TermsAcceptedAt = 'backend_editorial_newsletter_terms_accepted_at';

    case TermsAcceptedBy = 'backend_editorial_newsletter_terms_accepted_by';
}
