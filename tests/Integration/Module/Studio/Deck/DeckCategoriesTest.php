<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\DeckCategory;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The presentation categories, managed from the decks screen: created,
 * renamed, ordered and deleted by whoever holds `studio.deck_categories.manage`,
 * and nobody else. Deleting one leaves its decks, uncategorised.
 */
final class DeckCategoriesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s d SET d.category = NULL', Deck::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s c', DeckCategory::class))->execute();

        parent::tearDown();
    }

    public function testCategoriesAreCreatedRenamedAndOrdered(): void
    {
        $audit = $this->create('Audits', '#bd4a55');
        $strategy = $this->create('Stratégies', null);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/decks/categories/%d/update', $strategy), ['name' => 'Stratégies éditoriales', 'color' => '#8b6cff']);
        self::assertResponseIsSuccessful();

        $categories = $this->post('/suite/studio/decks/categories/reorder', ['ids' => [$strategy, $audit]])['categories'];
        self::assertSame([$strategy, $audit], array_column($categories, 'id'));
        self::assertSame('Stratégies éditoriales', $categories[0]['name']);
        self::assertSame('#8b6cff', $categories[0]['color']);
    }

    public function testDeletingACategoryKeepsItsDecks(): void
    {
        $audit = $this->create('Audits', null);
        $this->client->jsonRequest('POST', '/suite/studio/decks/create', ['title' => 'Audit de mars', 'categoryId' => $audit]);
        self::assertResponseIsSuccessful();
        $deckId = (int) json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['deck']['id'];

        $payload = $this->post(sprintf('/suite/studio/decks/categories/%d/delete', $audit), []);
        self::assertSame([], $payload['categories'], 'the answer carries the list the window redraws');

        $this->entityManager->clear();
        $deck = $this->entityManager->find(Deck::class, $deckId);
        self::assertInstanceOf(Deck::class, $deck);
        self::assertNull($deck->getCategory());
    }

    public function testManagingCategoriesNeedsItsOwnRight(): void
    {
        $audit = $this->create('Audits', null);

        $user = new User();
        $user->setEmail('decks-'.bin2hex(random_bytes(4)).'@aurora.app')->setName('Équipier')->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])->setPassword('irrelevant')
            ->setPrivileges(['studio.decks.view', 'studio.decks.create', 'studio.decks.edit']);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user, 'admin');

        foreach ([
            ['/suite/studio/decks/categories/create', ['name' => 'Bilans']],
            [sprintf('/suite/studio/decks/categories/%d/update', $audit), ['name' => 'Repris']],
            ['/suite/studio/decks/categories/reorder', ['ids' => [$audit]]],
            [sprintf('/suite/studio/decks/categories/%d/delete', $audit), []],
        ] as [$path, $body]) {
            $this->client->jsonRequest('POST', $path, $body);
            self::assertResponseStatusCodeSame(403, $path);
        }

        $this->entityManager->remove($this->entityManager->find(User::class, $user->getId()));
        $this->entityManager->flush();
    }

    private function create(string $name, ?string $color): int
    {
        return (int) $this->post('/suite/studio/decks/categories/create', ['name' => $name, 'color' => $color])['categoryId'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $this->client->jsonRequest('POST', $path, $body);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
