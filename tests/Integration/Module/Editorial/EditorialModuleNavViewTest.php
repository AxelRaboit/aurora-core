<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Editorial's records became destinations, checked on the response the browser
 * actually gets.
 *
 * Three things sit between the module and the reader and each can be wrong on
 * its own: the redirect that gives a listing one address per record, the
 * resolver that has to pick Editorial for an Editorial route, and the menu
 * payload that carries the entries. A unit test on the module sees none of them.
 */
final class EditorialModuleNavViewTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $user);

        $this->client->loginUser($user, 'admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleNavViewOn(string $path): array
    {
        $this->client->request('GET', $path);

        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        $matched = preg_match(
            '/vue-component-value="core\/suite\/sidemenu\/AppSidemenu" data-symfony--ux-vue--vue-props-value="([^"]*)"/',
            (string) $this->client->getResponse()->getContent(),
            $matches,
        );
        self::assertSame(1, $matched, 'The side menu was not mounted on '.$path);

        $props = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        return $props['moduleNavView'] ?? [];
    }

    /**
     * A bare listing address used to show whatever the browser picked first.
     * Now it names a record, so the address can be sent to somebody.
     */
    public function testABareListingSendsTheReaderToItsFirstRecord(): void
    {
        foreach (['post-types', 'taxonomies', 'menus'] as $listing) {
            $this->client->request('GET', '/suite/editorial/'.$listing);

            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $listing);
            self::assertMatchesRegularExpression(
                '#/suite/editorial/'.$listing.'/\d+$#',
                (string) $this->client->getResponse()->headers->get('Location'),
                $listing,
            );
        }
    }

    /**
     * Forms are a list of their own rather than a redirect to the first: an
     * empty one renders too. {@see FormsScreensTest} holds the case with forms.
     */
    public function testTheFormsListRendersInsteadOfRedirecting(): void
    {
        $this->client->request('GET', '/suite/editorial/forms');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheMenuListsEveryRecordAsItsOwnEntry(): void
    {
        $view = $this->moduleNavViewOn('/suite/editorial/post-types');

        self::assertSame('editorial', $view['moduleId'] ?? null);

        $groups = [];
        foreach ($view['groups'] ?? [] as $group) {
            $groups[$group['id']] = $group['items'];
        }

        foreach (['post_types', 'taxonomies', 'menus'] as $family) {
            self::assertArrayHasKey($family, $groups);
            self::assertNotEmpty($groups[$family], $family);
        }

        // Forms are listed on their own screen, never one entry each here.
        self::assertArrayNotHasKey('forms', $groups);
    }

    /**
     * The entries are named after data, so they carry a literal label - a post
     * type is called what somebody typed. Handing that to the translator would
     * echo it back while warning about a missing key on every render.
     */
    public function testRecordEntriesCarryTheirOwnName(): void
    {
        $view = $this->moduleNavViewOn('/suite/editorial/post-types');

        $postTypes = [];
        foreach ($view['groups'] ?? [] as $group) {
            if ('post_types' === $group['id']) {
                $postTypes = $group['items'];
            }
        }

        self::assertNotEmpty($postTypes);
        foreach ($postTypes as $item) {
            self::assertNotSame('', (string) ($item['label'] ?? ''), $item['path'] ?? '?');
            self::assertMatchesRegularExpression('#/post-types/\d+$#', (string) $item['path']);
        }
    }

    /**
     * A taxonomy's terms and a content type's publications are figures, drawn
     * on the right of the name like every figure of the menu. They used to
     * stand in for a blank description ("6 termes"), the same fact written a
     * second way (sidemenu audit of 10/10/2026).
     */
    public function testTaxonomyAndTypeEntriesCarryTheirFigure(): void
    {
        $view = $this->moduleNavViewOn('/suite/editorial/post-types');

        $counted = 0;
        foreach ($view['groups'] ?? [] as $group) {
            if (!in_array($group['id'], ['taxonomies', 'post_types'], true)) {
                continue;
            }

            foreach ($group['items'] as $item) {
                self::assertIsInt($item['count'] ?? null, sprintf('%s / %s', $group['id'], $item['label'] ?? '?'));
                self::assertDoesNotMatchRegularExpression('/^\d+ /', (string) ($item['description'] ?? ''));
                ++$counted;
            }
        }

        self::assertGreaterThan(0, $counted);
    }

    /** Studio's pages show Studio alone, like every other module's. */
    public function testStudioPagesShowTheStudioView(): void
    {
        $view = $this->moduleNavViewOn('/suite/studio/customers');

        self::assertSame('studio', $view['moduleId'] ?? null);
        $routes = array_column($view['groups'][0]['items'] ?? [], 'route');
        self::assertContains('suite_studio_customers', $routes);
        foreach ($view['groups'][0]['items'] ?? [] as $item) {
            self::assertArrayNotHasKey('count', $item, 'Studio carries no figures');
        }
    }

    /**
     * Every entry shares one route name, so each needs a key of its own:
     * without it, hiding one record from the menu would hide the family, and
     * the active row would be all of them at once.
     */
    public function testEachRecordEntryHasItsOwnStableKey(): void
    {
        $view = $this->moduleNavViewOn('/suite/editorial/menus');

        $keys = [];
        foreach ($view['groups'] ?? [] as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'] ?? $item['route'];
            }
        }

        self::assertSame($keys, array_unique($keys));
    }
}
