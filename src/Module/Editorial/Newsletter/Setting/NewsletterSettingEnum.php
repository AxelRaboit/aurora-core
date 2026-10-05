<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Setting;

enum NewsletterSettingEnum: string
{
    case Enabled = 'suite_editorial_newsletter_enabled';

    /** Which provider the key below belongs to: 'brevo' or 'mailchimp'. */
    case Provider = 'suite_editorial_newsletter_provider';

    /** Stored encrypted. */
    case ApiKey = 'suite_editorial_newsletter_api_key';

    /** Brevo's numeric list id, or Mailchimp's audience id. */
    case ListId = 'suite_editorial_newsletter_list_id';

    /**
     * Whether a new address waits for the visitor to confirm it by email.
     * On unless switched off: an address typed on a public page proves
     * nothing about who typed it.
     */
    case DoubleOptIn = 'suite_editorial_newsletter_double_opt_in';
    /** Brevo's double opt-in email template, which Brevo requires to send it. */
    case BrevoTemplateId = 'suite_editorial_newsletter_brevo_template_id';
    /**
     * The privacy policy the form links to. Required to switch the module on:
     * the GDPR asks that a visitor be told, where the address is collected,
     * who receives it, why, and how to withdraw.
     */
    case PrivacyUrl = 'suite_editorial_newsletter_privacy_url';
    case TermsAcceptedAt = 'suite_editorial_newsletter_terms_accepted_at';

    case TermsAcceptedBy = 'suite_editorial_newsletter_terms_accepted_by';
}
