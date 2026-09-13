<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\General;

use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManagerInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The overview page, end to end.
 *
 * Its whole value is that a count appears without anyone going looking for it,
 * so the assertion is made on the rendered payload rather than on the service:
 * a source that is not tagged, a module toggle that is spelled wrong or a
 * template that forgets a prop all leave the service green and the page blank.
 */
final class TrashOverviewPageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testThePageListsTheTrashesOfTheEnabledModules(): void
    {
        $this->client->request('GET', '/backend/trash');

        self::assertResponseIsSuccessful();

        $payload = $this->trashPayload();
        $keys = array_column($payload, 'key');

        self::assertContains('ged_documents', $keys);
        self::assertContains('ged_folders', $keys);
        self::assertContains('ged_categories', $keys);
        self::assertContains('editorial_posts', $keys);
        self::assertContains('notes_markdown', $keys);
    }

    public function testSomethingDeletedIsCountedWithTheDateItFell(): void
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $categoryManager = $container->get(DocumentCategoryManagerInterface::class);

        $before = $this->rowFor('ged_categories')['count'];

        $category = (new DocumentCategory())->setName('Corbeille test')->setSlug('corbeille-test-'.uniqid());
        $entityManager->persist($category);
        $entityManager->flush();
        $categoryManager->delete($category);

        $row = $this->rowFor('ged_categories');

        self::assertSame($before + 1, $row['count']);
        self::assertNotNull($row['oldestDeletedAt'], 'the page can only say how long is left once it knows when it fell');

        // The row carries what it takes to act on it without leaving the page.
        self::assertStringContainsString('__id__', (string) $row['restorePath']);
        self::assertStringContainsString('__id__', (string) $row['forceDeletePath']);
        self::assertContains(
            $category->getId(),
            array_column($row['items'], 'id'),
            'the category that was just deleted is listed',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $key): array
    {
        $this->client->request('GET', '/backend/trash');
        self::assertResponseIsSuccessful();

        foreach ($this->trashPayload() as $row) {
            if ($key === $row['key']) {
                return $row;
            }
        }

        self::fail(sprintf('No "%s" row on the overview page.', $key));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trashPayload(): array
    {
        $node = $this->client->getCrawler()
            ->filter('[data-symfony--ux-vue--vue-component-value="general/backend/trash/TrashApp"]')
            ->first();
        self::assertGreaterThan(0, $node->count(), 'the page mounts no Vue component');

        $props = json_decode((string) $node->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);

        return $props['trashes'];
    }
}
