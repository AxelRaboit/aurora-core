<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * The board of a space, end to end.
 *
 * Weighted towards the three things it does that a list does not: it arrives
 * with steps already on it, it moves a card between two of them without a form,
 * and it refuses to let one space reach into another's.
 */
final class SpaceBoardTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columns;

    private SpaceContentItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->items = $container->get(SpaceContentItemRepository::class);
    }

    protected function tearDown(): void
    {
        // Cards, then steps, then spaces, then customers: each points at the
        // one after it.
        foreach ([SpaceContentItem::class, SpaceContentColumn::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testANewSpaceArrivesWithAUsableBoard(): void
    {
        $space = $this->givenSpace();

        $columns = $this->columns->findForSpace($space);

        // Five steps, in order, seeded at creation rather than on first visit:
        // a screen that seeds itself on a GET makes two tabs race to create the
        // same rows.
        self::assertCount(5, $columns);
        self::assertSame([0, 1, 2, 3, 4], array_map(
            static fn ($column): int => $column->getPosition(),
            $columns,
        ));
        self::assertSame('Idées', $columns[0]->getName());
        self::assertSame('Publié', $columns[4]->getName());
    }

    public function testTheBoardScreenRenders(): void
    {
        $space = $this->givenSpace();

        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAnIdeaIsRecordedWithoutADate(): void
    {
        $space = $this->givenSpace();
        $column = $this->columns->findForSpace($space)[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => 'Portrait de l\'équipe',
            'body' => "Photo de groupe devant l'atelier.",
            'columnId' => $column->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $items = $this->payload()['items'];
        self::assertCount(1, $items);
        // The nullable date is the point: an idea exists before anybody knows
        // when it goes out, and it lives on the board until then.
        self::assertNull($items[0]['scheduledAt']);
        self::assertNull($items[0]['scheduledAtLocal']);
        self::assertSame($column->getId(), $items[0]['columnId']);
    }

    public function testATypedHourIsReadInTheSpaceZone(): void
    {
        $space = $this->givenSpace('Europe/Madrid');
        $column = $this->columns->findForSpace($space)[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => 'Lancement',
            'columnId' => $column->getId(),
            'scheduledAt' => '2026-12-01T09:00',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $item = $this->payload()['items'][0];
        // Nine in Madrid is eight UTC in December, and the wall clock comes
        // back unchanged so the form never has to convert it.
        self::assertSame('2026-12-01T08:00:00+00:00', (new DateTimeImmutable($item['scheduledAt']))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame('2026-12-01T09:00', $item['scheduledAtLocal']);
    }

    public function testACardMovesBetweenStepsAndKeepsTheOrderSent(): void
    {
        $space = $this->givenSpace();
        $columns = $this->columns->findForSpace($space);

        $first = $this->givenItem($space, $columns[0], 'Un');
        $second = $this->givenItem($space, $columns[0], 'Deux');

        // Both cards land in the second step, in the reverse order.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/reorder', $space->getId()), [
            'columnId' => $columns[1]->getId(),
            'itemIds' => [$second['id'], $first['id']],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $byId = [];
        foreach ($this->payload()['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        self::assertSame($columns[1]->getId(), $byId[$first['id']]['columnId']);
        self::assertSame($columns[1]->getId(), $byId[$second['id']]['columnId']);
        self::assertSame(0, $byId[$second['id']]['position']);
        self::assertSame(1, $byId[$first['id']]['position']);
    }

    public function testAStepFromAnotherSpaceIsRefused(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace(customerName: 'Autre client', siret: '39860733100024');

        $foreignColumn = $this->columns->findForSpace($theirs)[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $mine->getId()), [
            'title' => 'Carte égarée',
            'columnId' => $foreignColumn->getId(),
        ]);

        // The one thing a per-client space must never allow. A 422 under the
        // field, and nothing written.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('columnId', $this->payload()['errors']);
        self::assertSame(0, $this->items->countForSpace($mine));
    }

    public function testACardOfAnotherSpaceIsNotFoundUnderThisOne(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace(customerName: 'Client voisin', siret: '44306184100047');

        $foreign = $this->givenItem($theirs, $this->columns->findForSpace($theirs)[0], 'Pas à moi');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/delete', $mine->getId(), $foreign['id']));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->items->countForSpace($theirs));
    }

    public function testAStepHoldingCardsIsNotDeleted(): void
    {
        $space = $this->givenSpace();
        $column = $this->columns->findForSpace($space)[0];
        $this->givenItem($space, $column, 'Occupée');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/delete', $space->getId(), $column->getId()));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('column', $this->payload()['errors']);
        self::assertCount(5, $this->columns->findForSpace($space));
    }

    public function testAnEmptyStepIsDeleted(): void
    {
        $space = $this->givenSpace();
        $column = $this->columns->findForSpace($space)[4];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/delete', $space->getId(), $column->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(4, $this->payload()['columns']);
    }

    /**
     * A space deleted while its board is full.
     *
     * The case the foreign keys are arranged for: the cascade reaches the steps
     * and the cards at once, and a `RESTRICT` between them would have refused a
     * deletion the user is entitled to.
     */
    public function testASpaceIsDeletedWithItsWholeBoard(): void
    {
        $space = $this->givenSpace();
        $column = $this->columns->findForSpace($space)[0];
        $this->givenItem($space, $column, 'Emportée');

        $this->client->jsonRequest('POST', sprintf('/backend/studio/spaces/%d/delete', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->columns->findForSpace($space));
        self::assertSame(0, $this->items->countForSpace($space));
    }

    private function givenSpace(
        string $timezone = 'Europe/Paris',
        string $customerName = 'Client du tableau',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('board@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace de '.$customerName,
            'customerId' => $customer->getId(),
            'timezone' => $timezone,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /** @return array<string, mixed> */
    private function givenItem(CustomerSpace $space, $column, string $title): array
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $column->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $items = $this->payload()['items'];

        foreach ($items as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }

        self::fail(sprintf('the card "%s" was not in the answer', $title));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
