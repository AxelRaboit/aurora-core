<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Security;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

use function array_column;
use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function iterator_to_array;
use function preg_match_all;
use function str_starts_with;

/**
 * Switching a module off closes it at the door, not only in the menu.
 *
 * Each toggle is turned off in turn and one of its routes is asked for: it
 * must not answer. Found by the audit of 04/10/2026, where Notes and the media
 * library's sub-modules only hid their menu entries while their routes kept
 * answering. A toggle added to `ModuleParameterEnum` without a line here fails
 * the coverage test, so the question is asked of every new one.
 */
final class ModuleTogglesCloseTheirRoutesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    /** @return iterable<string, array{ModuleParameterEnum, string, int}> toggle, a route behind it, the expected status */
    public static function toggleProvider(): iterable
    {
        // The dashboard switched off sends to the profile rather than a 404:
        // it is the page one lands on after signing in.
        yield 'general' => [ModuleParameterEnum::GeneralSuite, 'suite_dashboard', 302];
        yield 'dashboard' => [ModuleParameterEnum::GeneralDashboard, 'suite_dashboard', 302];
        yield 'platform' => [ModuleParameterEnum::PlatformSuite, 'suite_platform_users', 404];
        yield 'users' => [ModuleParameterEnum::PlatformUsers, 'suite_platform_users', 404];
        yield 'configuration' => [ModuleParameterEnum::ConfigurationSuite, 'suite_configuration_settings', 404];
        yield 'settings' => [ModuleParameterEnum::ConfigurationSettings, 'suite_configuration_settings', 404];
        yield 'themes' => [ModuleParameterEnum::ConfigurationThemes, 'suite_configuration_themes', 404];
        yield 'editorial' => [ModuleParameterEnum::EditorialSuite, 'suite_editorial_posts', 404];
        yield 'editorial front' => [ModuleParameterEnum::EditorialFrontend, 'editorial_home', 404];
        yield 'posts' => [ModuleParameterEnum::EditorialPosts, 'suite_editorial_posts', 404];
        yield 'post types' => [ModuleParameterEnum::EditorialPostTypes, 'suite_editorial_post_types', 404];
        yield 'taxonomies' => [ModuleParameterEnum::EditorialTaxonomies, 'suite_editorial_taxonomies', 404];
        yield 'menus' => [ModuleParameterEnum::EditorialMenus, 'suite_editorial_menus', 404];
        yield 'seo' => [ModuleParameterEnum::EditorialSeo, 'editorial_sitemap', 404];
        yield 'comments' => [ModuleParameterEnum::EditorialComments, 'suite_editorial_comments', 404];
        yield 'forms' => [ModuleParameterEnum::EditorialForms, 'suite_editorial_forms', 404];
        yield 'ged' => [ModuleParameterEnum::GedSuite, 'suite_ged_documents', 404];
        yield 'documents' => [ModuleParameterEnum::GedDocuments, 'suite_ged_documents', 404];
        yield 'ged categories' => [ModuleParameterEnum::GedCategories, 'suite_ged_categories', 404];
        yield 'tags' => [ModuleParameterEnum::GedTags, 'suite_ged_tags', 404];
        yield 'folders' => [ModuleParameterEnum::GedFolders, 'suite_ged_folders_create', 404];
        yield 'ged front' => [ModuleParameterEnum::GedFrontend, 'frontend_ged_index', 404];
        yield 'planning' => [ModuleParameterEnum::PlanningSuite, 'suite_planning_calendar', 404];
        yield 'notes' => [ModuleParameterEnum::NotesSuite, 'suite_notes_markdown', 404];
        yield 'markdown' => [ModuleParameterEnum::NotesMarkdown, 'suite_notes_markdown', 404];
        // Sharing switched off closes the guest list - and, elsewhere, the
        // unauthenticated write route, which is the half that matters most.
        yield 'notes collaboration' => [ModuleParameterEnum::NotesCollaboration, 'suite_notes_markdown_people_list', 404];
        yield 'studio' => [ModuleParameterEnum::StudioSuite, 'suite_studio_customers', 404];
        yield 'customers' => [ModuleParameterEnum::StudioCustomers, 'suite_studio_customers', 404];
        yield 'spaces' => [ModuleParameterEnum::StudioSpaces, 'suite_studio_spaces', 404];
        yield 'contracts' => [ModuleParameterEnum::StudioContracts, 'suite_studio_contracts', 404];
        yield 'deliverables' => [ModuleParameterEnum::StudioDeliverables, 'suite_studio_deliverables', 404];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->loginUser(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']), 'admin');
    }

    #[DataProvider('toggleProvider')]
    public function testAToggleOffClosesItsRoute(ModuleParameterEnum $toggle, string $route, int $expected): void
    {
        $settings = self::getContainer()->get(SettingRepository::class);
        $checker = self::getContainer()->get(ModuleAccessChecker::class);
        $definition = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->get($route);
        self::assertNotNull($definition, $route);

        // Any placeholder gets a value: the gate answers before the controller
        // looks at it.
        preg_match_all('/\{(\w+)\}/', $definition->getPath(), $placeholders);
        $parameters = [];
        foreach ($placeholders[1] as $name) {
            $parameters[$name] = 'locale' === $name ? 'fr' : '1';
        }

        $url = self::getContainer()->get(RouterInterface::class)->generate($route, $parameters);
        $method = $definition->getMethods()[0] ?? 'GET';

        $settings->set($toggle->value, '0');
        $checker->reset();
        try {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame($expected, $route.' with '.$toggle->value.' off');
        } finally {
            $settings->set($toggle->value, '1');
            $checker->reset();
        }
    }

    public function testEveryToggleIsAskedTheQuestion(): void
    {
        $covered = array_map(static fn (array $row): string => $row[0]->value, iterator_to_array(self::toggleProvider()));
        $all = array_column(ModuleParameterEnum::cases(), 'value');
        $all = array_filter($all, static fn (string $key): bool => str_starts_with($key, 'modules_'));

        $missing = array_diff($all, $covered);

        self::assertSame([], array_values($missing), 'Toggles with no route asked of them: '.implode(', ', $missing));
    }
}
