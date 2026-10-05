<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Enum;

use Aurora\Core\Module\Toggle\ModuleToggle;

enum ModuleParameterEnum: string implements ApplicationParameterEnumInterface
{
    public const MODULE = 'modules';

    // Top-level modules - suite (admin UI)
    case GeneralSuite = 'modules_general_suite';
    case PlatformSuite = 'modules_platform_suite';
    case ConfigurationSuite = 'modules_configuration_suite';
    case EditorialSuite = 'modules_editorial_suite';
    case GedSuite = 'modules_ged_suite';
    case PlanningSuite = 'modules_planning_suite';
    case NotesSuite = 'modules_notes_suite';
    case StudioSuite = 'modules_studio_suite';

    // Top-level modules - frontend (public site)

    // Sub-modules - Core
    case GeneralDashboard = 'modules_general_dashboard';

    // Sub-modules - Platform
    case PlatformUsers = 'modules_platform_users';

    // Sub-modules - Configuration
    case ConfigurationSettings = 'modules_configuration_settings';
    case ConfigurationThemes = 'modules_configuration_themes';

    // Sub-modules - Editorial
    case EditorialFrontend = 'modules_editorial_frontend';
    case EditorialPosts = 'modules_editorial_posts';
    case EditorialPostTypes = 'modules_editorial_post_types';
    case EditorialTaxonomies = 'modules_editorial_taxonomies';
    case EditorialMenus = 'modules_editorial_menus';
    case EditorialSeo = 'modules_editorial_seo';
    case EditorialComments = 'modules_editorial_comments';
    case EditorialForms = 'modules_editorial_forms';

    // Sub-modules - GED
    case GedDocuments = 'modules_ged_documents';
    case GedCategories = 'modules_ged_categories';
    case GedTags = 'modules_ged_tags';
    case GedFolders = 'modules_ged_folders';
    case GedFrontend = 'modules_ged_frontend';
    case NotesMarkdown = 'modules_notes_markdown';

    // Sub-modules - Studio
    case StudioCustomers = 'modules_studio_customers';
    case StudioSpaces = 'modules_studio_spaces';
    case StudioContracts = 'modules_studio_contracts';
    case StudioDecks = 'modules_studio_decks';
    case StudioDeliverables = 'modules_studio_deliverables';

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::GeneralSuite => 'suite.modules.general_suite',
            self::GeneralDashboard => 'suite.nav.dashboard',
            self::PlatformSuite => 'suite.modules.platform_suite',
            self::PlatformUsers => 'suite.nav.users',
            self::ConfigurationSuite => 'suite.modules.configuration',
            self::ConfigurationSettings => 'suite.nav.settings',
            self::ConfigurationThemes => 'suite.nav.themes',
            self::EditorialSuite => 'suite.modules.editorial_suite',
            self::EditorialFrontend => 'suite.modules.editorial_frontend',
            self::EditorialPosts => 'suite.nav.posts',
            self::EditorialPostTypes => 'suite.nav.post_types',
            self::EditorialTaxonomies => 'suite.nav.taxonomies',
            self::EditorialMenus => 'suite.nav.menus',
            self::EditorialSeo => 'suite.nav.seo',
            self::EditorialComments => 'suite.nav.comments',
            self::EditorialForms => 'suite.nav.forms',
            self::GedSuite => 'suite.modules.ged_suite',
            self::PlanningSuite => 'suite.modules.planning_suite',

            self::GedDocuments => 'suite.nav.documents',
            self::GedCategories => 'suite.nav.ged_categories',
            self::GedTags => 'suite.nav.ged_tags',
            self::GedFolders => 'suite.nav.ged_folders',
            self::GedFrontend => 'suite.modules.ged_frontend',
            self::NotesSuite => 'suite.modules.notes_suite',
            self::NotesMarkdown => 'suite.nav.notes_markdown',
            self::StudioSuite => 'suite.modules.studio_suite',
            self::StudioCustomers => 'suite.nav.studio_customers',
            self::StudioSpaces => 'suite.nav.studio_spaces',
            self::StudioContracts => 'suite.nav.studio_contract_templates',
            self::StudioDecks => 'suite.nav.studio_decks',
            self::StudioDeliverables => 'suite.nav.studio_deliverables',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::GeneralSuite => 'suite.modules.general_suite_description',
            self::GeneralDashboard => 'suite.nav.dashboard_description',
            self::PlatformSuite => 'suite.modules.platform_suite_description',
            self::PlatformUsers => 'suite.nav.users_description',
            self::ConfigurationSuite => 'suite.modules.configuration_description',
            self::ConfigurationSettings => 'suite.nav.settings_description',
            self::ConfigurationThemes => 'suite.nav.themes_description',
            self::EditorialSuite => 'suite.modules.editorial_suite_description',
            self::EditorialFrontend => 'suite.modules.editorial_frontend_description',
            self::EditorialPosts => 'suite.nav.posts_description',
            self::EditorialPostTypes => 'suite.nav.post_types_description',
            self::EditorialTaxonomies => 'suite.nav.taxonomies_description',
            self::EditorialMenus => 'suite.nav.menus_description',
            self::EditorialSeo => 'suite.nav.seo_description',
            self::EditorialComments => 'suite.nav.comments_description',
            self::EditorialForms => 'suite.nav.forms_description',
            self::GedSuite => 'suite.modules.ged_suite_description',
            self::PlanningSuite => 'suite.modules.planning_suite_description',

            self::GedDocuments => 'suite.nav.documents_description',
            self::GedCategories => 'suite.nav.ged_categories_description',
            self::GedTags => 'suite.nav.ged_tags_description',
            self::GedFolders => 'suite.nav.ged_folders_description',
            self::GedFrontend => 'suite.modules.ged_frontend_description',
            self::NotesSuite => 'suite.modules.notes_suite_description',
            self::NotesMarkdown => 'suite.nav.notes_markdown_description',
            self::StudioSuite => 'suite.modules.studio_suite_description',
            self::StudioCustomers => 'suite.nav.studio_customers_description',
            self::StudioSpaces => 'suite.nav.studio_spaces_description',
            self::StudioContracts => 'suite.nav.studio_contract_templates_description',
            self::StudioDecks => 'suite.nav.studio_decks_description',
            self::StudioDeliverables => 'suite.nav.studio_deliverables_description',
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
            self::GeneralDashboard => self::GeneralSuite,
            self::PlatformUsers => self::PlatformSuite,
            self::ConfigurationSettings, self::ConfigurationThemes => self::ConfigurationSuite,
            self::EditorialFrontend, self::EditorialPosts, self::EditorialPostTypes, self::EditorialTaxonomies, self::EditorialMenus, self::EditorialSeo, self::EditorialComments, self::EditorialForms => self::EditorialSuite,
            self::GedDocuments, self::GedCategories, self::GedTags, self::GedFolders, self::GedFrontend => self::GedSuite,
            self::NotesMarkdown => self::NotesSuite,
            self::StudioCustomers, self::StudioContracts, self::StudioDecks, self::StudioDeliverables, self::StudioSpaces => self::StudioSuite,
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
            self::GeneralDashboard => self::GeneralSuite->value,
            // Platform sub-modules
            self::PlatformUsers => self::PlatformSuite->value,
            // Configuration sub-modules
            self::ConfigurationSettings,
            self::ConfigurationThemes => self::ConfigurationSuite->value,
            // Editorial sub-modules
            // A post needs a type to be, so posts follow post types.
            self::EditorialPosts => self::EditorialPostTypes->value,
            // Nothing to publish without posts.
            self::EditorialFrontend => self::EditorialPosts->value,
            self::EditorialPostTypes => self::EditorialSuite->value,
            // Terms only make sense once a post type can carry them.
            self::EditorialTaxonomies => self::EditorialPostTypes->value,
            // Navigation points at published pages, so it follows the front.
            self::EditorialMenus => self::EditorialFrontend->value,
            // Sitemap, robots and feed describe the public site; with no
            // public site there is nothing for them to describe.
            self::EditorialSeo => self::EditorialFrontend->value,
            // A comment is a reply to something published; with no public
            // site there is nothing to reply to.
            self::EditorialComments => self::EditorialFrontend->value,
            // A form is published at its own public URL, so it needs the front.
            self::EditorialForms => self::EditorialFrontend->value,
            // GED sub-modules
            self::GedDocuments => self::GedSuite->value,
            self::GedCategories => self::GedSuite->value,
            self::GedTags => self::GedSuite->value,
            self::GedFolders => self::GedSuite->value,
            self::GedFrontend => self::GedSuite->value,
            self::NotesMarkdown => self::NotesSuite->value,
            // Studio sub-modules
            self::StudioCustomers => self::StudioSuite->value,
            // A contract is signed with somebody, and that somebody is a
            // customer. Templates without the customer screen would build
            // documents with nobody to address them to.
            self::StudioContracts => self::StudioCustomers->value,
            // Decks hang off the module and nothing else. A deck may name the
            // customer it was written for, but the field is nullable on
            // purpose: a strategy deck written for oneself has no client, and
            // requiring the customer screen would make that case impossible.
            self::StudioDecks => self::StudioSuite->value,
            // Un livrable de Studio n'a ni client ni espace : comme une
            // présentation, il ne dépend que du module.
            self::StudioDeliverables => self::StudioSuite->value,
            // A space is the space of a customer: it is created from the
            // customer list and its every screen names the company it belongs
            // to. Without that screen a space would have nobody to be for,
            // which is the same reason contracts follow customers.
            self::StudioSpaces => self::StudioCustomers->value,
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
            self::GeneralSuite => 'general',
            self::PlatformSuite => 'platform',
            self::ConfigurationSuite => 'configuration',
            self::EditorialSuite => 'editorial',
            self::GedSuite => 'ged',
            self::PlanningSuite => 'planning',
            self::NotesSuite => 'notes',
            self::StudioSuite => 'studio',
            default => null,
        };
    }

    /**
     * No placeholder by default - override on a per-case basis when an
     * example value is genuinely clearer than the description alone.
     */
    public function getPlaceholder(): ?string
    {
        return null;
    }

    public function getOffWarning(): ?string
    {
        return null;
    }
}
