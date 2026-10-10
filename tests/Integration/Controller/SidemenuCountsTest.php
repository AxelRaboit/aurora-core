<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The side menu prints a figure beside the entries a module counts.
 *
 * Read from the menu's own props as the page renders them, so the test sees
 * what the reader would: the figure of an entry, the absence of one on an
 * entry nobody counts, and a publications count that matches the list's.
 */
final class SidemenuCountsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testCountedEntriesCarryTheirFigure(): void
    {
        $items = $this->menuItems();

        self::assertArrayHasKey('suite_editorial_posts', $items);
        self::assertSame(
            static::getContainer()->get(PostRepository::class)->countNotTrashed(),
            $items['suite_editorial_posts']['count'] ?? null,
            'A developer sees every publication, so the figure is the whole list.',
        );
        self::assertSame(
            static::getContainer()->get(UserRepository::class)->count([]),
            $items['suite_platform_users']['count'] ?? null,
        );
    }

    public function testAnEntryNobodyCountsHasNoFigure(): void
    {
        $items = $this->menuItems();

        self::assertArrayHasKey('suite_editorial_menus', $items);
        self::assertArrayNotHasKey('count', $items['suite_editorial_menus']);
    }

    public function testThePublicationsFigureFollowsTheListScope(): void
    {
        $postRepository = static::getContainer()->get(PostRepository::class);
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);

        // The query a contributor's figure comes from counts exactly what their
        // list shows: their own publications, out of the trash.
        $list = $postRepository->findPaginated(1, 'fr', 1, authorId: $admin->getId());
        self::assertSame($list['total'], $postRepository->countNotTrashed($admin->getId()));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function menuItems(): array
    {
        $this->client->request('GET', '/suite');
        self::assertResponseIsSuccessful();

        $node = $this->client->getCrawler()->filter('[data-symfony--ux-vue--vue-component-value="core/suite/sidemenu/AppSidemenu"]');
        self::assertSame(1, $node->count());

        $props = json_decode((string) $node->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);

        $items = [];
        foreach ($props['navSections'] as $section) {
            foreach ($section['items'] as $item) {
                $items[$item['key']] = $item;
            }
        }

        return $items;
    }
}
