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

    /**
     * Whether a new address waits for the visitor to confirm it by email.
     * On unless switched off: an address typed on a public page proves
     * nothing about who typed it.
     */
    case DoubleOptIn = 'backend_editorial_newsletter_double_opt_in';
    /** Brevo's double opt-in email template, which Brevo requires to send it. */
    case BrevoTemplateId = 'backend_editorial_newsletter_brevo_template_id';
    /**
     * The privacy policy the form links to. Required to switch the module on:
     * the GDPR asks that a visitor be told, where the address is collected,
     * who receives it, why, and how to withdraw.
     */
    case PrivacyUrl = 'backend_editorial_newsletter_privacy_url';
    case TermsAcceptedAt = 'backend_editorial_newsletter_terms_accepted_at';

    case TermsAcceptedBy = 'backend_editorial_newsletter_terms_accepted_by';
}
