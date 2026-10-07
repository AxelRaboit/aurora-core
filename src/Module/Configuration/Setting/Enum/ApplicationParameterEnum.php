<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Enum;

use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Core\Storage\Access\UploadPolicy;
use Aurora\Core\Storage\Access\UploadPolicyProvider;

use const JSON_THROW_ON_ERROR;

enum ApplicationParameterEnum: string implements ApplicationParameterEnumInterface
{
    case SiteName = 'site_name';
    case SiteDescription = 'site_description';
    case SiteUrl = 'site_url';
    case AdminEmail = 'suite_email';
    case DefaultLocale = 'default_locale';
    case SingleLocaleMode = 'single_locale_mode';
    case PostsPerPage = 'posts_per_page';
    /**
     * The ceiling, in megabytes, on anything filed anywhere.
     *
     * Read by {@see UploadPolicyProvider}. It
     * was read by nobody until 2026-09-16: the ceilings lived in
     * `UploadPolicy` while this sat in the panel, so lowering it changed
     * nothing.
     *
     * `allowed_upload_extensions` stood next to it and was removed rather than
     * wired, because the library declines to keep a type allow-list on
     * purpose - {@see UploadPolicy} argues it
     * at length, and the wall is at the serving end. Making it real would have
     * been a restriction nobody decided on; leaving it was a control that lied.
     */
    case MaxUploadSizeMb = 'max_upload_size_mb';
    case FileVersionsLimit = 'file_versions_limit';
    case MediaCreditVisible = 'media_credit_visible';
    case Timezone = 'timezone';
    case DateFormat = 'date_format';
    case CommentsEnabled = 'comments_enabled';
    case CommentModerationEnabled = 'comment_moderation_enabled';
    case MaintenanceMode = 'maintenance_mode';
    case AdminRegistrationEnabled = 'suite_registration_enabled';
    case AdminAccessRequestEnabled = 'suite_platform_access_request_enabled';
    case FrontLoginEnabled = 'frontend_login_enabled';
    case FrontRegistrationEnabled = 'frontend_registration_enabled';
    case PostRevisionsLimit = 'post_revisions_limit';
    case TrashAutoPurgeDays = 'trash_auto_purge_days';
    case FormSubmissionRetentionDays = 'form_submission_retention_days';
    case HomepagePostId = 'homepage_post_id';
    case DefaultFront = 'default_front';
    case LogoMediaId = 'logo_media_id';
    case FaviconMediaId = 'favicon_media_id';
    // The site name next to the logo, in the back office's top bar on a
    // phone. Shown by default; turned off, the logo stands alone (02/10/2026).
    case SuiteBarSiteNameOnPhone = 'suite_bar_site_name_on_phone';
    case SeoTitleTemplate = 'seo_title_template';
    case SeoDefaultDescription = 'seo_default_description';
    case SeoDefaultOgImage = 'seo_default_og_image';
    case SeoTwitterHandle = 'seo_twitter_handle';
    case EmailLocale = 'email_locale';
    case CoreUserPrefix = 'core_user_prefix';
    case CoreMediaPrefix = 'core_media_prefix';
    case CoreAccessRequestPrefix = 'core_access_request_prefix';
    case CoreAuditLogPrefix = 'core_audit_log_prefix';
    case CoreResetPasswordPrefix = 'core_reset_password_prefix';
    case CoreMediaFolderPrefix = 'core_media_folder_prefix';
    case CoreMenuItemPrefix = 'core_menu_item_prefix';
    case StudioContractPrefix = 'studio_contract_prefix';

    // The provider's own identity, printed into every contract. Settings
    // rather than trame wording: it is the same block in every document, and
    // a bank change should not mean editing every trame.
    case StudioProviderName = 'studio_provider_name';
    case StudioProviderRepresentative = 'studio_provider_representative';
    case StudioProviderAddress = 'studio_provider_address';
    case StudioProviderSiret = 'studio_provider_siret';
    case StudioProviderApeCode = 'studio_provider_ape_code';
    case StudioProviderVatMention = 'studio_provider_vat_mention';
    case StudioProviderEmail = 'studio_provider_email';
    case StudioProviderPhone = 'studio_provider_phone';
    case StudioProviderBankHolder = 'studio_provider_bank_holder';
    case StudioProviderBankIban = 'studio_provider_bank_iban';
    case StudioProviderBankBic = 'studio_provider_bank_bic';
    case StudioProviderBankName = 'studio_provider_bank_name';

    /**
     * How long a sealed contract has to be kept, and how hard the module
     * makes it to lose one before then.
     *
     * Five years is the floor for a commercial obligation, ten is what a
     * service provider is usually advised to keep. It is a setting rather
     * than a constant because the right number depends on the trade, and
     * whoever answers for the archive is the one who should choose it.
     */
    case StudioContractRetentionYears = 'studio_contract_retention_years';

    /**
     * The automatic chasing of a contract sent and not signed.
     *
     * Off by default, deliberately: mail leaving on its own to somebody
     * else's customer is a decision, not a default. A reminder hands out a
     * fresh address and revokes the previous one, exactly like a manual
     * resend, because that is the only way the mail can carry the door.
     */
    case StudioContractReminderEnabled = 'studio_contract_reminder_enabled';

    case StudioContractReminderDays = 'studio_contract_reminder_days';

    case StudioContractReminderMax = 'studio_contract_reminder_max';

    /** How long a signing address stays valid, in days. Thirty by default. */
    case StudioContractLinkDays = 'studio_contract_link_days';
    case NavSectionAliases = 'nav_section_aliases';
    case NavItemAliases = 'nav_item_aliases';
    case NavSectionOrder = 'nav_section_order';
    case NavItemOrder = 'nav_item_order';
    case ColorPickerPresets = 'color_picker_presets';
    case SuitePalette = 'suite_palette';
    case EmailAccentFollowsTheme = 'email_accent_follows_theme';
    case EmailAccentColor = 'email_accent_color';
    case EmailBackgroundColor = 'email_background_color';
    case EmailHeadingColor = 'email_heading_color';
    case EmailTextColor = 'email_text_color';

    /**
     * Default palette for AppColorPicker. JSON-encoded list of hex strings.
     *
     * @var list<string>
     */
    public const array DEFAULT_COLOR_PICKER_PRESETS = [
        '#ef4444', '#f97316', '#f59e0b', '#eab308',
        '#84cc16', '#22c55e', '#10b981', '#14b8a6',
        '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6',
        '#a855f7', '#ec4899', '#f43f5e', '#64748b',
    ];

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SiteName => 'suite.parameters.site_name.label',
            self::SiteDescription => 'suite.parameters.site_description.label',
            self::SiteUrl => 'suite.parameters.site_url.label',
            self::AdminEmail => 'suite.parameters.admin_email.label',
            self::DefaultLocale => 'suite.parameters.default_locale.label',
            self::SingleLocaleMode => 'suite.parameters.single_locale_mode.label',
            self::PostsPerPage => 'suite.parameters.posts_per_page.label',
            self::MaxUploadSizeMb => 'suite.parameters.max_upload_size_mb.label',
            self::Timezone => 'suite.parameters.timezone.label',
            self::DateFormat => 'suite.parameters.date_format.label',
            self::CommentsEnabled => 'suite.parameters.comments_enabled.label',
            self::CommentModerationEnabled => 'suite.parameters.comment_moderation_enabled.label',
            self::MaintenanceMode => 'suite.parameters.maintenance_mode.label',
            self::AdminRegistrationEnabled => 'suite.parameters.admin_registration_enabled.label',
            self::AdminAccessRequestEnabled => 'suite.parameters.admin_access_request_enabled.label',
            self::FrontLoginEnabled => 'suite.parameters.front_login_enabled.label',
            self::FrontRegistrationEnabled => 'suite.parameters.front_registration_enabled.label',
            self::PostRevisionsLimit => 'suite.parameters.post_revisions_limit.label',
            self::FileVersionsLimit => 'suite.parameters.file_versions_limit.label',
            self::MediaCreditVisible => 'suite.parameters.media_credit_visible.label',
            self::TrashAutoPurgeDays => 'suite.parameters.trash_auto_purge_days.label',
            self::FormSubmissionRetentionDays => 'suite.parameters.form_submission_retention_days.label',
            self::HomepagePostId => 'suite.parameters.homepage_post_id.label',
            self::DefaultFront => 'suite.parameters.default_front.label',
            self::LogoMediaId => 'suite.parameters.logo_media_id.label',
            self::FaviconMediaId => 'suite.parameters.favicon_media_id.label',
            self::SuiteBarSiteNameOnPhone => 'suite.parameters.suite_bar_site_name_on_phone.label',
            self::SeoTitleTemplate => 'suite.parameters.seo_title_template.label',
            self::SeoDefaultDescription => 'suite.parameters.seo_default_description.label',
            self::SeoDefaultOgImage => 'suite.parameters.seo_default_og_image.label',
            self::SeoTwitterHandle => 'suite.parameters.seo_twitter_handle.label',
            self::EmailLocale => 'suite.parameters.email_locale.label',
            self::CoreUserPrefix => 'suite.parameters.core_user_prefix.label',
            self::CoreMediaPrefix => 'suite.parameters.core_media_prefix.label',
            self::CoreAccessRequestPrefix => 'suite.parameters.core_access_request_prefix.label',
            self::CoreAuditLogPrefix => 'suite.parameters.core_audit_log_prefix.label',
            self::CoreResetPasswordPrefix => 'suite.parameters.core_reset_password_prefix.label',
            self::CoreMediaFolderPrefix => 'suite.parameters.core_media_folder_prefix.label',
            self::CoreMenuItemPrefix => 'suite.parameters.core_menu_item_prefix.label',
            self::StudioContractPrefix => 'suite.parameters.studio_contract_prefix.label',
            self::StudioProviderName => 'suite.parameters.studio_provider_name.label',
            self::StudioProviderRepresentative => 'suite.parameters.studio_provider_representative.label',
            self::StudioProviderAddress => 'suite.parameters.studio_provider_address.label',
            self::StudioProviderSiret => 'suite.parameters.studio_provider_siret.label',
            self::StudioProviderApeCode => 'suite.parameters.studio_provider_ape_code.label',
            self::StudioProviderVatMention => 'suite.parameters.studio_provider_vat_mention.label',
            self::StudioProviderEmail => 'suite.parameters.studio_provider_email.label',
            self::StudioProviderPhone => 'suite.parameters.studio_provider_phone.label',
            self::StudioProviderBankHolder => 'suite.parameters.studio_provider_bank_holder.label',
            self::StudioProviderBankIban => 'suite.parameters.studio_provider_bank_iban.label',
            self::StudioProviderBankBic => 'suite.parameters.studio_provider_bank_bic.label',
            self::StudioProviderBankName => 'suite.parameters.studio_provider_bank_name.label',
            self::StudioContractRetentionYears => 'suite.parameters.studio_contract_retention_years.label',
            self::StudioContractReminderEnabled => 'suite.parameters.studio_contract_reminder_enabled.label',
            self::StudioContractReminderDays => 'suite.parameters.studio_contract_reminder_days.label',
            self::StudioContractLinkDays => 'suite.parameters.studio_contract_link_days.label',
            self::StudioContractReminderMax => 'suite.parameters.studio_contract_reminder_max.label',
            self::NavSectionAliases => 'suite.parameters.nav_section_aliases.label',
            self::NavItemAliases => 'suite.parameters.nav_item_aliases.label',
            self::NavSectionOrder => 'suite.parameters.nav_section_order.label',
            self::NavItemOrder => 'suite.parameters.nav_item_order.label',
            self::ColorPickerPresets => 'suite.parameters.color_picker_presets.label',
            self::SuitePalette => 'suite.parameters.suite_palette.label',
            self::EmailAccentFollowsTheme => 'suite.parameters.email_accent_follows_theme.label',
            self::EmailAccentColor => 'suite.parameters.email_accent_color.label',
            self::EmailBackgroundColor => 'suite.parameters.email_background_color.label',
            self::EmailHeadingColor => 'suite.parameters.email_heading_color.label',
            self::EmailTextColor => 'suite.parameters.email_text_color.label',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SiteName => 'suite.parameters.site_name.description',
            self::SiteDescription => 'suite.parameters.site_description.description',
            self::SiteUrl => 'suite.parameters.site_url.description',
            self::AdminEmail => 'suite.parameters.admin_email.description',
            self::DefaultLocale => 'suite.parameters.default_locale.description',
            self::SingleLocaleMode => 'suite.parameters.single_locale_mode.description',
            self::PostsPerPage => 'suite.parameters.posts_per_page.description',
            self::MaxUploadSizeMb => 'suite.parameters.max_upload_size_mb.description',
            self::Timezone => 'suite.parameters.timezone.description',
            self::DateFormat => 'suite.parameters.date_format.description',
            self::CommentsEnabled => 'suite.parameters.comments_enabled.description',
            self::CommentModerationEnabled => 'suite.parameters.comment_moderation_enabled.description',
            self::MaintenanceMode => 'suite.parameters.maintenance_mode.description',
            self::AdminRegistrationEnabled => 'suite.parameters.admin_registration_enabled.description',
            self::AdminAccessRequestEnabled => 'suite.parameters.admin_access_request_enabled.description',
            self::FrontLoginEnabled => 'suite.parameters.front_login_enabled.description',
            self::FrontRegistrationEnabled => 'suite.parameters.front_registration_enabled.description',
            self::PostRevisionsLimit => 'suite.parameters.post_revisions_limit.description',
            self::FileVersionsLimit => 'suite.parameters.file_versions_limit.description',
            self::MediaCreditVisible => 'suite.parameters.media_credit_visible.description',
            self::TrashAutoPurgeDays => 'suite.parameters.trash_auto_purge_days.description',
            self::FormSubmissionRetentionDays => 'suite.parameters.form_submission_retention_days.description',
            self::HomepagePostId => 'suite.parameters.homepage_post_id.description',
            self::DefaultFront => 'suite.parameters.default_front.description',
            self::LogoMediaId => 'suite.parameters.logo_media_id.description',
            self::FaviconMediaId => 'suite.parameters.favicon_media_id.description',
            self::SuiteBarSiteNameOnPhone => 'suite.parameters.suite_bar_site_name_on_phone.description',
            self::SeoTitleTemplate => 'suite.parameters.seo_title_template.description',
            self::SeoDefaultDescription => 'suite.parameters.seo_default_description.description',
            self::SeoDefaultOgImage => 'suite.parameters.seo_default_og_image.description',
            self::SeoTwitterHandle => 'suite.parameters.seo_twitter_handle.description',
            self::EmailLocale => 'suite.parameters.email_locale.description',
            self::CoreUserPrefix => 'suite.parameters.core_user_prefix.description',
            self::CoreMediaPrefix => 'suite.parameters.core_media_prefix.description',
            self::CoreAccessRequestPrefix => 'suite.parameters.core_access_request_prefix.description',
            self::CoreAuditLogPrefix => 'suite.parameters.core_audit_log_prefix.description',
            self::CoreResetPasswordPrefix => 'suite.parameters.core_reset_password_prefix.description',
            self::CoreMediaFolderPrefix => 'suite.parameters.core_media_folder_prefix.description',
            self::CoreMenuItemPrefix => 'suite.parameters.core_menu_item_prefix.description',
            self::StudioContractPrefix => 'suite.parameters.studio_contract_prefix.description',
            self::StudioProviderName => 'suite.parameters.studio_provider_name.description',
            self::StudioProviderRepresentative => 'suite.parameters.studio_provider_representative.description',
            self::StudioProviderAddress => 'suite.parameters.studio_provider_address.description',
            self::StudioProviderSiret => 'suite.parameters.studio_provider_siret.description',
            self::StudioProviderApeCode => 'suite.parameters.studio_provider_ape_code.description',
            self::StudioProviderVatMention => 'suite.parameters.studio_provider_vat_mention.description',
            self::StudioProviderEmail => 'suite.parameters.studio_provider_email.description',
            self::StudioProviderPhone => 'suite.parameters.studio_provider_phone.description',
            self::StudioProviderBankHolder => 'suite.parameters.studio_provider_bank_holder.description',
            self::StudioProviderBankIban => 'suite.parameters.studio_provider_bank_iban.description',
            self::StudioProviderBankBic => 'suite.parameters.studio_provider_bank_bic.description',
            self::StudioProviderBankName => 'suite.parameters.studio_provider_bank_name.description',
            self::StudioContractRetentionYears => 'suite.parameters.studio_contract_retention_years.description',
            self::StudioContractReminderEnabled => 'suite.parameters.studio_contract_reminder_enabled.description',
            self::StudioContractReminderDays => 'suite.parameters.studio_contract_reminder_days.description',
            self::StudioContractLinkDays => 'suite.parameters.studio_contract_link_days.description',
            self::StudioContractReminderMax => 'suite.parameters.studio_contract_reminder_max.description',
            self::NavSectionAliases => 'suite.parameters.nav_section_aliases.description',
            self::NavItemAliases => 'suite.parameters.nav_item_aliases.description',
            self::NavSectionOrder => 'suite.parameters.nav_section_order.description',
            self::NavItemOrder => 'suite.parameters.nav_item_order.description',
            self::ColorPickerPresets => 'suite.parameters.color_picker_presets.description',
            self::SuitePalette => 'suite.parameters.suite_palette.description',
            self::EmailAccentFollowsTheme => 'suite.parameters.email_accent_follows_theme.description',
            self::EmailAccentColor => 'suite.parameters.email_accent_color.description',
            self::EmailBackgroundColor => 'suite.parameters.email_background_color.description',
            self::EmailHeadingColor => 'suite.parameters.email_heading_color.description',
            self::EmailTextColor => 'suite.parameters.email_text_color.description',
        };
    }

    public function getDefaultValue(): string
    {
        return match ($this) {
            self::SiteName => 'Aurora',
            self::SiteDescription => 'Propulsé par Aurora',
            // Seeded empty, and not with a plausible value. An `http://localhost`
            // or an `admin@aurora.app` shown on the settings screen reads as a
            // choice already made: nobody corrects it, and the site goes to
            // production announcing an unreachable address. Empty, the field
            // says what it is. See Context::siteUrl() and MailService::adminEmail(),
            // which both know what to do with a missing value.
            self::SiteUrl => '',
            self::AdminEmail => '',
            self::DefaultLocale => 'fr',
            self::SingleLocaleMode => '0',
            self::PostsPerPage => '10',
            self::MaxUploadSizeMb => '100',
            self::Timezone => 'Europe/Paris',
            self::DateFormat => 'short',
            self::CommentsEnabled => '1',
            self::CommentModerationEnabled => '1',
            self::MaintenanceMode => '0',
            self::AdminRegistrationEnabled => '0',
            self::AdminAccessRequestEnabled => '1',
            self::FrontLoginEnabled => '1',
            self::FrontRegistrationEnabled => '0',
            self::PostRevisionsLimit => '20',
            self::FileVersionsLimit => '3',
            self::MediaCreditVisible => '1',
            self::TrashAutoPurgeDays => '30',
            // Off. Submissions are business records, not a bin: a version that
            // starts deleting them on its own, the day it is installed, is a
            // version that loses a client's mail without being asked to.
            self::FormSubmissionRetentionDays => '0',
            self::HomepagePostId => '',
            self::DefaultFront => '',
            self::LogoMediaId => '',
            self::FaviconMediaId => '',
            self::SuiteBarSiteNameOnPhone => '1',
            self::SeoTitleTemplate => '{title} - {siteName}',
            self::SeoDefaultDescription => '',
            self::SeoDefaultOgImage => '',
            self::SeoTwitterHandle => '',
            self::EmailLocale => '',
            self::CoreUserPrefix => SequencePrefixEnum::User->value,
            self::CoreMediaPrefix => SequencePrefixEnum::Media->value,
            self::CoreAccessRequestPrefix => SequencePrefixEnum::AccessRequest->value,
            self::CoreAuditLogPrefix => SequencePrefixEnum::AuditLog->value,
            self::CoreResetPasswordPrefix => SequencePrefixEnum::ResetPasswordRequest->value,
            self::CoreMediaFolderPrefix => SequencePrefixEnum::MediaFolder->value,
            self::CoreMenuItemPrefix => SequencePrefixEnum::MenuItem->value,
            self::StudioContractPrefix => SequencePrefixEnum::Contract->value,
            self::StudioProviderName => '',
            self::StudioProviderRepresentative => '',
            self::StudioProviderAddress => '',
            self::StudioProviderSiret => '',
            self::StudioProviderApeCode => '',
            self::StudioProviderVatMention => '',
            self::StudioProviderEmail => '',
            self::StudioProviderPhone => '',
            self::StudioProviderBankHolder => '',
            self::StudioProviderBankIban => '',
            self::StudioProviderBankBic => '',
            self::StudioProviderBankName => '',
            self::StudioContractRetentionYears => '10',
            self::StudioContractReminderEnabled => '0',
            self::StudioContractReminderDays => '3',
            self::StudioContractLinkDays => '30',
            self::StudioContractReminderMax => '2',
            self::NavSectionAliases => '{}',
            self::NavItemAliases => '{}',
            self::NavSectionOrder => '[]',
            self::NavItemOrder => '{}',
            self::ColorPickerPresets => json_encode(self::DEFAULT_COLOR_PICKER_PRESETS, JSON_THROW_ON_ERROR),
            self::SuitePalette => '{}',
            // The colours email.css hard-codes: an email goes out as before.
            self::EmailAccentFollowsTheme => '1',
            self::EmailAccentColor => '#059669',
            self::EmailBackgroundColor => '#f5f3ff',
            self::EmailHeadingColor => '#1e1b4b',
            self::EmailTextColor => '#52525b',
        };
    }

    public function getType(): string
    {
        return match ($this) {
            self::PostsPerPage, self::MaxUploadSizeMb, self::PostRevisionsLimit, self::TrashAutoPurgeDays, self::FormSubmissionRetentionDays, self::FileVersionsLimit, self::StudioContractRetentionYears, self::StudioContractReminderDays, self::StudioContractReminderMax, self::StudioContractLinkDays => 'int',
            self::HomepagePostId => 'post',
            self::DefaultFront, self::DefaultLocale, self::EmailLocale, self::Timezone, self::DateFormat => 'select',
            self::CommentsEnabled, self::CommentModerationEnabled, self::MaintenanceMode, self::AdminRegistrationEnabled, self::AdminAccessRequestEnabled, self::FrontLoginEnabled, self::FrontRegistrationEnabled, self::SingleLocaleMode, self::MediaCreditVisible, self::StudioContractReminderEnabled, self::SuiteBarSiteNameOnPhone, self::EmailAccentFollowsTheme => 'bool',
            self::LogoMediaId, self::FaviconMediaId, self::SeoDefaultOgImage => 'media',
            self::ColorPickerPresets, self::SuitePalette => 'json',
            self::EmailAccentColor, self::EmailBackgroundColor, self::EmailHeadingColor, self::EmailTextColor => 'color',
            default => 'string',
        };
    }

    public function isAdminAccessible(): bool
    {
        return match ($this->getGroup()) {
            'general', 'reading', 'localization', 'branding', 'seo', 'system', 'email', 'sequences', 'media', 'navigation', 'appearance', 'studio' => true,
            default => false,
        };
    }

    public function getGroup(): string
    {
        return match ($this) {
            self::SiteName, self::SiteDescription, self::SiteUrl, self::AdminEmail => 'general',
            self::DefaultLocale, self::SingleLocaleMode, self::Timezone, self::DateFormat => 'localization',
            self::PostsPerPage, self::CommentsEnabled, self::CommentModerationEnabled, self::PostRevisionsLimit, self::TrashAutoPurgeDays, self::FormSubmissionRetentionDays, self::HomepagePostId, self::DefaultFront => 'reading',
            self::MaxUploadSizeMb, self::FileVersionsLimit, self::MediaCreditVisible => 'media',
            self::MaintenanceMode, self::AdminRegistrationEnabled, self::AdminAccessRequestEnabled, self::FrontLoginEnabled, self::FrontRegistrationEnabled => 'system',
            self::LogoMediaId, self::FaviconMediaId, self::SuiteBarSiteNameOnPhone => 'branding',
            self::SeoTitleTemplate, self::SeoDefaultDescription, self::SeoDefaultOgImage, self::SeoTwitterHandle => 'seo',
            self::CoreUserPrefix, self::CoreMediaPrefix, self::CoreAccessRequestPrefix, self::CoreAuditLogPrefix, self::CoreResetPasswordPrefix, self::CoreMediaFolderPrefix, self::CoreMenuItemPrefix, self::StudioContractPrefix => 'sequences',
            self::StudioProviderName, self::StudioProviderRepresentative, self::StudioProviderAddress, self::StudioProviderSiret, self::StudioProviderApeCode, self::StudioProviderVatMention, self::StudioProviderEmail, self::StudioProviderPhone, self::StudioProviderBankHolder, self::StudioProviderBankIban, self::StudioProviderBankBic, self::StudioProviderBankName, self::StudioContractRetentionYears, self::StudioContractReminderEnabled, self::StudioContractReminderDays, self::StudioContractReminderMax, self::StudioContractLinkDays => 'studio',
            self::EmailLocale, self::EmailAccentFollowsTheme, self::EmailAccentColor, self::EmailBackgroundColor, self::EmailHeadingColor, self::EmailTextColor => 'email',
            self::NavSectionAliases, self::NavItemAliases, self::NavSectionOrder, self::NavItemOrder => 'navigation',
            self::ColorPickerPresets, self::SuitePalette => 'appearance',
        };
    }

    /**
     * Sample value shown inside the input. Only set on the fields where
     * an example is meaningfully clearer than the description alone -
     * the rest fall through to the `default => null` arm.
     */
    public function getPlaceholder(): ?string
    {
        return match ($this) {
            self::SiteName => 'suite.parameters.site_name.placeholder',
            self::SiteDescription => 'suite.parameters.site_description.placeholder',
            self::SiteUrl => 'suite.parameters.site_url.placeholder',
            self::AdminEmail => 'suite.parameters.admin_email.placeholder',
            self::PostsPerPage => 'suite.parameters.posts_per_page.placeholder',
            self::SeoTitleTemplate => 'suite.parameters.seo_title_template.placeholder',
            self::SeoDefaultDescription => 'suite.parameters.seo_default_description.placeholder',
            self::SeoTwitterHandle => 'suite.parameters.seo_twitter_handle.placeholder',
            self::MaxUploadSizeMb => 'suite.parameters.max_upload_size_mb.placeholder',
            default => null,
        };
    }

    /**
     * What to say before this is switched off, for the few where "off" is a
     * decision rather than a preference.
     *
     * Null for nearly everything. A confirmation on an ordinary toggle is a
     * click people learn to dismiss, and that is exactly how the ones that
     * matter stop being read.
     */
    public function getOffWarning(): ?string
    {
        return match ($this) {
            self::MediaCreditVisible => 'suite.parameters.media_credit_visible.off_warning',
            default => null,
        };
    }
}
