<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Enum;

use Aurora\Core\Sequence\SequencePrefixEnum;

use const JSON_THROW_ON_ERROR;

enum ApplicationParameterEnum: string implements ApplicationParameterEnumInterface
{
    case SiteName = 'site_name';
    case SiteDescription = 'site_description';
    case SiteUrl = 'site_url';
    case AdminEmail = 'backend_email';
    case DefaultLocale = 'default_locale';
    case SingleLocaleMode = 'single_locale_mode';
    case PostsPerPage = 'posts_per_page';
    case MaxUploadSizeMb = 'max_upload_size_mb';
    case AllowedUploadExtensions = 'allowed_upload_extensions';
    case FileVersionsLimit = 'file_versions_limit';
    case Timezone = 'timezone';
    case DateFormat = 'date_format';
    case CommentsEnabled = 'comments_enabled';
    case CommentModerationEnabled = 'comment_moderation_enabled';
    case MaintenanceMode = 'maintenance_mode';
    case AdminRegistrationEnabled = 'backend_registration_enabled';
    case AdminAccessRequestEnabled = 'backend_platform_access_request_enabled';
    case FrontLoginEnabled = 'frontend_login_enabled';
    case FrontRegistrationEnabled = 'frontend_registration_enabled';
    case PostRevisionsLimit = 'post_revisions_limit';
    case TrashAutoPurgeDays = 'trash_auto_purge_days';
    case HomepagePostId = 'homepage_post_id';
    case DefaultFront = 'default_front';
    case LogoMediaId = 'logo_media_id';
    case FaviconMediaId = 'favicon_media_id';
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
    case NavSectionAliases = 'nav_section_aliases';
    case NavItemAliases = 'nav_item_aliases';
    case NavSectionOrder = 'nav_section_order';
    case NavItemOrder = 'nav_item_order';
    case ColorPickerPresets = 'color_picker_presets';

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
            self::SiteName => 'backend.parameters.site_name.label',
            self::SiteDescription => 'backend.parameters.site_description.label',
            self::SiteUrl => 'backend.parameters.site_url.label',
            self::AdminEmail => 'backend.parameters.admin_email.label',
            self::DefaultLocale => 'backend.parameters.default_locale.label',
            self::SingleLocaleMode => 'backend.parameters.single_locale_mode.label',
            self::PostsPerPage => 'backend.parameters.posts_per_page.label',
            self::MaxUploadSizeMb => 'backend.parameters.max_upload_size_mb.label',
            self::AllowedUploadExtensions => 'backend.parameters.allowed_upload_extensions.label',
            self::Timezone => 'backend.parameters.timezone.label',
            self::DateFormat => 'backend.parameters.date_format.label',
            self::CommentsEnabled => 'backend.parameters.comments_enabled.label',
            self::CommentModerationEnabled => 'backend.parameters.comment_moderation_enabled.label',
            self::MaintenanceMode => 'backend.parameters.maintenance_mode.label',
            self::AdminRegistrationEnabled => 'backend.parameters.admin_registration_enabled.label',
            self::AdminAccessRequestEnabled => 'backend.parameters.admin_access_request_enabled.label',
            self::FrontLoginEnabled => 'backend.parameters.front_login_enabled.label',
            self::FrontRegistrationEnabled => 'backend.parameters.front_registration_enabled.label',
            self::PostRevisionsLimit => 'backend.parameters.post_revisions_limit.label',
            self::FileVersionsLimit => 'backend.parameters.file_versions_limit.label',
            self::TrashAutoPurgeDays => 'backend.parameters.trash_auto_purge_days.label',
            self::HomepagePostId => 'backend.parameters.homepage_post_id.label',
            self::DefaultFront => 'backend.parameters.default_front.label',
            self::LogoMediaId => 'backend.parameters.logo_media_id.label',
            self::FaviconMediaId => 'backend.parameters.favicon_media_id.label',
            self::SeoTitleTemplate => 'backend.parameters.seo_title_template.label',
            self::SeoDefaultDescription => 'backend.parameters.seo_default_description.label',
            self::SeoDefaultOgImage => 'backend.parameters.seo_default_og_image.label',
            self::SeoTwitterHandle => 'backend.parameters.seo_twitter_handle.label',
            self::EmailLocale => 'backend.parameters.email_locale.label',
            self::CoreUserPrefix => 'backend.parameters.core_user_prefix.label',
            self::CoreMediaPrefix => 'backend.parameters.core_media_prefix.label',
            self::CoreAccessRequestPrefix => 'backend.parameters.core_access_request_prefix.label',
            self::CoreAuditLogPrefix => 'backend.parameters.core_audit_log_prefix.label',
            self::CoreResetPasswordPrefix => 'backend.parameters.core_reset_password_prefix.label',
            self::CoreMediaFolderPrefix => 'backend.parameters.core_media_folder_prefix.label',
            self::CoreMenuItemPrefix => 'backend.parameters.core_menu_item_prefix.label',
            self::NavSectionAliases => 'backend.parameters.nav_section_aliases.label',
            self::NavItemAliases => 'backend.parameters.nav_item_aliases.label',
            self::NavSectionOrder => 'backend.parameters.nav_section_order.label',
            self::NavItemOrder => 'backend.parameters.nav_item_order.label',
            self::ColorPickerPresets => 'backend.parameters.color_picker_presets.label',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SiteName => 'backend.parameters.site_name.description',
            self::SiteDescription => 'backend.parameters.site_description.description',
            self::SiteUrl => 'backend.parameters.site_url.description',
            self::AdminEmail => 'backend.parameters.admin_email.description',
            self::DefaultLocale => 'backend.parameters.default_locale.description',
            self::SingleLocaleMode => 'backend.parameters.single_locale_mode.description',
            self::PostsPerPage => 'backend.parameters.posts_per_page.description',
            self::MaxUploadSizeMb => 'backend.parameters.max_upload_size_mb.description',
            self::AllowedUploadExtensions => 'backend.parameters.allowed_upload_extensions.description',
            self::Timezone => 'backend.parameters.timezone.description',
            self::DateFormat => 'backend.parameters.date_format.description',
            self::CommentsEnabled => 'backend.parameters.comments_enabled.description',
            self::CommentModerationEnabled => 'backend.parameters.comment_moderation_enabled.description',
            self::MaintenanceMode => 'backend.parameters.maintenance_mode.description',
            self::AdminRegistrationEnabled => 'backend.parameters.admin_registration_enabled.description',
            self::AdminAccessRequestEnabled => 'backend.parameters.admin_access_request_enabled.description',
            self::FrontLoginEnabled => 'backend.parameters.front_login_enabled.description',
            self::FrontRegistrationEnabled => 'backend.parameters.front_registration_enabled.description',
            self::PostRevisionsLimit => 'backend.parameters.post_revisions_limit.description',
            self::FileVersionsLimit => 'backend.parameters.file_versions_limit.description',
            self::TrashAutoPurgeDays => 'backend.parameters.trash_auto_purge_days.description',
            self::HomepagePostId => 'backend.parameters.homepage_post_id.description',
            self::DefaultFront => 'backend.parameters.default_front.description',
            self::LogoMediaId => 'backend.parameters.logo_media_id.description',
            self::FaviconMediaId => 'backend.parameters.favicon_media_id.description',
            self::SeoTitleTemplate => 'backend.parameters.seo_title_template.description',
            self::SeoDefaultDescription => 'backend.parameters.seo_default_description.description',
            self::SeoDefaultOgImage => 'backend.parameters.seo_default_og_image.description',
            self::SeoTwitterHandle => 'backend.parameters.seo_twitter_handle.description',
            self::EmailLocale => 'backend.parameters.email_locale.description',
            self::CoreUserPrefix => 'backend.parameters.core_user_prefix.description',
            self::CoreMediaPrefix => 'backend.parameters.core_media_prefix.description',
            self::CoreAccessRequestPrefix => 'backend.parameters.core_access_request_prefix.description',
            self::CoreAuditLogPrefix => 'backend.parameters.core_audit_log_prefix.description',
            self::CoreResetPasswordPrefix => 'backend.parameters.core_reset_password_prefix.description',
            self::CoreMediaFolderPrefix => 'backend.parameters.core_media_folder_prefix.description',
            self::CoreMenuItemPrefix => 'backend.parameters.core_menu_item_prefix.description',
            self::NavSectionAliases => 'backend.parameters.nav_section_aliases.description',
            self::NavItemAliases => 'backend.parameters.nav_item_aliases.description',
            self::NavSectionOrder => 'backend.parameters.nav_section_order.description',
            self::NavItemOrder => 'backend.parameters.nav_item_order.description',
            self::ColorPickerPresets => 'backend.parameters.color_picker_presets.description',
        };
    }

    public function getDefaultValue(): string
    {
        return match ($this) {
            self::SiteName => 'Aurora',
            self::SiteDescription => 'Propulsé par Aurora',
            // Semes vides, et non avec une valeur plausible. Un `http://localhost`
            // ou un `admin@aurora.app` affiche dans l'ecran de reglages se lit
            // comme un choix deja fait : personne ne le corrige, et le site part
            // en production en annoncant une adresse injoignable. Vide, le champ
            // dit ce qu'il est. Cf. Context::siteUrl() et MailService::adminEmail(),
            // qui savent tous deux quoi faire d'une valeur absente.
            self::SiteUrl => '',
            self::AdminEmail => '',
            self::DefaultLocale => 'fr',
            self::SingleLocaleMode => '0',
            self::PostsPerPage => '10',
            self::MaxUploadSizeMb => '20',
            self::AllowedUploadExtensions => 'jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,zip',
            self::Timezone => 'Europe/Paris',
            self::DateFormat => 'd/m/Y',
            self::CommentsEnabled => '1',
            self::CommentModerationEnabled => '1',
            self::MaintenanceMode => '0',
            self::AdminRegistrationEnabled => '0',
            self::AdminAccessRequestEnabled => '1',
            self::FrontLoginEnabled => '1',
            self::FrontRegistrationEnabled => '0',
            self::PostRevisionsLimit => '20',
            self::FileVersionsLimit => '3',
            self::TrashAutoPurgeDays => '30',
            self::HomepagePostId => '',
            self::DefaultFront => '',
            self::LogoMediaId => '',
            self::FaviconMediaId => '',
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
            self::NavSectionAliases => '{}',
            self::NavItemAliases => '{}',
            self::NavSectionOrder => '[]',
            self::NavItemOrder => '{}',
            self::ColorPickerPresets => json_encode(self::DEFAULT_COLOR_PICKER_PRESETS, JSON_THROW_ON_ERROR),
        };
    }

    public function getType(): string
    {
        return match ($this) {
            self::PostsPerPage, self::MaxUploadSizeMb, self::PostRevisionsLimit, self::TrashAutoPurgeDays, self::FileVersionsLimit => 'int',
            self::HomepagePostId => 'post',
            self::DefaultFront, self::DefaultLocale, self::EmailLocale, self::Timezone => 'select',
            self::CommentsEnabled, self::CommentModerationEnabled, self::MaintenanceMode, self::AdminRegistrationEnabled, self::AdminAccessRequestEnabled, self::FrontLoginEnabled, self::FrontRegistrationEnabled, self::SingleLocaleMode => 'bool',
            self::LogoMediaId, self::FaviconMediaId, self::SeoDefaultOgImage => 'media',
            self::ColorPickerPresets => 'json',
            default => 'string',
        };
    }

    public function isAdminAccessible(): bool
    {
        return match ($this->getGroup()) {
            'general', 'reading', 'localization', 'branding', 'seo', 'system', 'email', 'sequences', 'media', 'navigation', 'appearance' => true,
            default => false,
        };
    }

    public function getGroup(): string
    {
        return match ($this) {
            self::SiteName, self::SiteDescription, self::SiteUrl, self::AdminEmail => 'general',
            self::DefaultLocale, self::SingleLocaleMode, self::Timezone, self::DateFormat => 'localization',
            self::PostsPerPage, self::CommentsEnabled, self::CommentModerationEnabled, self::PostRevisionsLimit, self::TrashAutoPurgeDays, self::HomepagePostId, self::DefaultFront => 'reading',
            self::MaxUploadSizeMb, self::AllowedUploadExtensions, self::FileVersionsLimit => 'media',
            self::MaintenanceMode, self::AdminRegistrationEnabled, self::AdminAccessRequestEnabled, self::FrontLoginEnabled, self::FrontRegistrationEnabled => 'system',
            self::LogoMediaId, self::FaviconMediaId => 'branding',
            self::SeoTitleTemplate, self::SeoDefaultDescription, self::SeoDefaultOgImage, self::SeoTwitterHandle => 'seo',
            self::CoreUserPrefix, self::CoreMediaPrefix, self::CoreAccessRequestPrefix, self::CoreAuditLogPrefix, self::CoreResetPasswordPrefix, self::CoreMediaFolderPrefix, self::CoreMenuItemPrefix => 'sequences',
            self::EmailLocale => 'email',
            self::NavSectionAliases, self::NavItemAliases, self::NavSectionOrder, self::NavItemOrder => 'navigation',
            self::ColorPickerPresets => 'appearance',
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
            self::SiteName => 'backend.parameters.site_name.placeholder',
            self::SiteDescription => 'backend.parameters.site_description.placeholder',
            self::SiteUrl => 'backend.parameters.site_url.placeholder',
            self::AdminEmail => 'backend.parameters.admin_email.placeholder',
            self::PostsPerPage => 'backend.parameters.posts_per_page.placeholder',
            self::SeoTitleTemplate => 'backend.parameters.seo_title_template.placeholder',
            self::SeoDefaultDescription => 'backend.parameters.seo_default_description.placeholder',
            self::SeoTwitterHandle => 'backend.parameters.seo_twitter_handle.placeholder',
            self::MaxUploadSizeMb => 'backend.parameters.max_upload_size_mb.placeholder',
            self::AllowedUploadExtensions => 'backend.parameters.allowed_upload_extensions.placeholder',
            default => null,
        };
    }
}
