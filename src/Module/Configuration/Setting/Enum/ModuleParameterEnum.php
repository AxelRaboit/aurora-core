<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Enum;

use Aurora\Core\Module\Toggle\ModuleToggle;

enum ModuleParameterEnum: string implements ApplicationParameterEnumInterface
{
    public const MODULE = 'modules';

    // Top-level modules — backend (admin UI)
    case GeneralBackend = 'modules_general_backend';
    case PlatformBackend = 'modules_platform_backend';
    case ConfigurationBackend = 'modules_configuration_backend';
    case MediaBackend = 'modules_media_backend';
    case GedBackend = 'modules_ged_backend';

    // Top-level modules — frontend (public site)

    // Sub-modules — Core
    case GeneralDashboard = 'modules_general_dashboard';

    // Sub-modules — Platform
    case PlatformUsers = 'modules_platform_users';
    case PlatformAgencies = 'modules_platform_agencies';
    case PlatformServices = 'modules_platform_services';

    // Sub-modules — Configuration
    case ConfigurationSettings = 'modules_configuration_settings';
    case ConfigurationThemes = 'modules_configuration_themes';

    // Sub-modules — Media
    case MediaLibrary = 'modules_media_library';

    // Sub-modules — Editorial

    // Sub-modules — GED
    case GedDocuments = 'modules_ged_documents';
    case GedCategories = 'modules_ged_categories';
    case GedTags = 'modules_ged_tags';
    case GedFolders = 'modules_ged_folders';
    case GedFrontend = 'modules_ged_frontend';

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::GeneralBackend => 'backend.modules.general_backend',
            self::GeneralDashboard => 'backend.nav.dashboard',
            self::PlatformBackend => 'backend.modules.platform_backend',
            self::PlatformUsers => 'backend.nav.users',
            self::PlatformAgencies => 'backend.nav.agencies',
            self::PlatformServices => 'backend.nav.services',
            self::ConfigurationBackend => 'backend.modules.configuration',
            self::ConfigurationSettings => 'backend.nav.settings',
            self::ConfigurationThemes => 'backend.nav.themes',
            self::MediaBackend => 'backend.modules.media_backend',
            self::MediaLibrary => 'backend.nav.media',
            self::GedBackend => 'backend.modules.ged_backend',

            self::GedDocuments => 'backend.nav.documents',
            self::GedCategories => 'backend.nav.ged_categories',
            self::GedTags => 'backend.nav.ged_tags',
            self::GedFolders => 'backend.nav.ged_folders',
            self::GedFrontend => 'backend.modules.ged_frontend',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::GeneralBackend => 'backend.modules.general_backend_description',
            self::GeneralDashboard => 'backend.nav.dashboard_description',
            self::PlatformBackend => 'backend.modules.platform_backend_description',
            self::PlatformUsers => 'backend.nav.users_description',
            self::PlatformAgencies => 'backend.nav.agencies_description',
            self::PlatformServices => 'backend.nav.services_description',
            self::ConfigurationBackend => 'backend.modules.configuration_description',
            self::ConfigurationSettings => 'backend.nav.settings_description',
            self::ConfigurationThemes => 'backend.nav.themes_description',
            self::MediaBackend => 'backend.modules.media_backend_description',
            self::MediaLibrary => 'backend.nav.media_description',
            self::GedBackend => 'backend.modules.ged_backend_description',

            self::GedDocuments => 'backend.nav.documents_description',
            self::GedCategories => 'backend.nav.ged_categories_description',
            self::GedTags => 'backend.nav.ged_tags_description',
            self::GedFolders => 'backend.nav.ged_folders_description',
            self::GedFrontend => 'backend.modules.ged_frontend_description',
        };
    }

    public function getDefaultValue(): string
    {
        return '1';
    }

    public function getType(): string
    {
        return 'bool';
    }

    public function getGroup(): string
    {
        return self::MODULE;
    }

    /**
     * Returns the parent ModuleParameterEnum case for sub-modules, null for top-level.
     */
    public function getParentCase(): ?self
    {
        return match ($this) {
            self::GeneralDashboard => self::GeneralBackend,
            self::PlatformUsers, self::PlatformAgencies,
            self::PlatformServices => self::PlatformBackend,
            self::ConfigurationSettings, self::ConfigurationThemes => self::ConfigurationBackend,
            self::MediaLibrary => self::MediaBackend,
            self::GedDocuments, self::GedCategories, self::GedTags, self::GedFolders, self::GedFrontend => self::GedBackend,
            default => null,
        };
    }

    /**
     * Returns the key of the parameter that must be active before this one can be enabled.
     * Defines the full dependency graph (top-level inter-module + sub-module chains).
     */
    public function getCascadeRequires(): ?string
    {
        return match ($this) {
            // Top-level inter-module dependencies
            // Core sub-modules
            self::GeneralDashboard => self::GeneralBackend->value,
            // Platform sub-modules
            self::PlatformUsers,
            self::PlatformAgencies,
            self::PlatformServices => self::PlatformBackend->value,
            // Configuration sub-modules
            self::ConfigurationSettings,
            self::ConfigurationThemes => self::ConfigurationBackend->value,
            // Media sub-modules
            self::MediaLibrary => self::MediaBackend->value,
            // Editorial sub-modules
            // GED sub-modules
            self::GedDocuments => self::GedBackend->value,
            self::GedCategories => self::GedBackend->value,
            self::GedTags => self::GedBackend->value,
            self::GedFolders => self::GedBackend->value,
            self::GedFrontend => self::GedBackend->value,
            default => null,
        };
    }

    /**
     * All descendant parameter keys (direct + transitive) that must be forced to '0'
     * when this parameter is turned off. Covers both cascade-requires children
     * and direct sub-modules (via getParentCase).
     *
     * @return list<string>
     */
    public function getCascadeDisableTargets(): array
    {
        $targets = [];
        foreach (self::cases() as $case) {
            if ($case->getCascadeRequires() === $this->value || $case->getParentCase() === $this) {
                $targets[] = $case->value;
                foreach ($case->getCascadeDisableTargets() as $transitive) {
                    $targets[] = $transitive;
                }
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Builds a {@see ModuleToggle} value object from this enum case so the
     * module can declare it via `ModuleToggleProviderInterface::getToggles()`.
     */
    public function toToggle(): ModuleToggle
    {
        return new ModuleToggle(
            key: $this->value,
            labelKey: $this->getLabel(),
            descriptionKey: $this->getDescription(),
            parentKey: $this->getCascadeRequires(),
            moduleId: $this->getModuleId(),
            displayParentKey: $this->getParentCase()?->value,
        );
    }

    /**
     * Returns the module identifier for top-level enabled cases, null for sub-modules.
     */
    public function getModuleId(): ?string
    {
        return match ($this) {
            self::GeneralBackend => 'general',
            self::PlatformBackend => 'platform',
            self::ConfigurationBackend => 'configuration',
            self::MediaBackend => 'media',
            self::GedBackend => 'ged',
            default => null,
        };
    }

    /**
     * No placeholder by default — override on a per-case basis when an
     * example value is genuinely clearer than the description alone.
     */
    public function getPlaceholder(): ?string
    {
        return null;
    }
}
