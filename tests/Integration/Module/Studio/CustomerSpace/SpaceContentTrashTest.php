<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Message\PurgeTrashedSpaceContentsMessage;
use Aurora\Module\Studio\SpaceContent\MessageHandler\PurgeTrashedSpaceContentsHandler;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Trash\SpaceContentsTrashSource;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkloadRow;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function array_map;
use function array_search;
use function html_entity_decode;
use function json_decode;
use function parse_url;
use function sprintf;

use const PHP_URL_PATH;

/**
 * The contents of a space in the common trash.
 *
 * A content item put in the trash leaves the board, the list, the calendar,
 * the counts and the client's page, keeps its thread and its files, and comes
 * back to its step on a restore - to the first step when its own was deleted
 * meanwhile. « Delete for good » and the scheduled purge are what destroy it.
 */
final class SpaceContentTrashTest extends IntegrationTestCase
{
    private const string SOURCE = 'studio.space_content';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    private SpaceContentItemRepository $items;

    private SpaceContentColumnRepository $columns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->items = self::getContainer()->get(SpaceContentItemRepository::class);
        $this->columns = self::getContainer()->get(SpaceContentColumnRepository::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach ([SpaceAccessLink::class, SpaceContentComment::class, SpaceContentItem::class, SpaceContentColumn::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType IN ('CustomerSpace', 'SpaceContentItem', 'SpaceContentColumn')", AuditLog::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s e WHERE e.sourceType = :source', PlanningEvent::class))->setParameter('source', self::SOURCE)->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s p WHERE p.sourceType = :source', Planning::class))->setParameter('source', self::SOURCE)->execute();

        parent::tearDown();
    }

    public function testATrashedCardLeavesEverythingAndComesBackToItsStep(): void
    {
        $space = $this->givenSpace('Espace des contenus');
        $id = (int) $space->getId();
        $review = $this->columns->findForSpace($space)[2];
        $itemId = $this->givenItem($id, (int) $review->getId(), 'Galette des rois', '+2 days 10:00');
        $other = $this->givenItem($id, (int) $review->getId(), 'Pain au levain', '+4 days 10:00');
        $this->post(sprintf('/workspace/%d/content/%d/comments', $id, $itemId), ['body' => 'Un mot du studio']);
        self::assertResponseIsSuccessful();
        $guestPath = $this->issueLink($id);

        self::assertStringContainsString('Galette des rois', $this->guestPage($guestPath));
        $this->client->loginUser($this->admin, 'admin');
        self::assertSame(2, $this->workload($space)->withClient);
        self::assertStringContainsString('Galette des rois', $this->editorialCalendar());

        $this->post(sprintf('/workspace/%d/content/%d/delete', $id, $itemId));
        self::assertResponseIsSuccessful();
        // Off the board the answer draws, and its thread with it.
        self::assertSame([$other], array_column($this->json()['items'], 'id'));
        self::assertArrayNotHasKey((string) $itemId, $this->json()['comments']);

        $this->entityManager->clear();
        $trashed = $this->items->findTrashed($itemId);
        self::assertNotNull($trashed);
        self::assertSame((int) $review->getId(), $trashed->getColumn()->getId());
        // Its thread is kept, for a restore.
        self::assertCount(1, $this->entityManager->getRepository(SpaceContentComment::class)->findBy(['item' => $itemId]));

        // Off the counts, the calendar, the planning and the client's page.
        self::assertSame(1, $this->items->countForSpace($space));
        self::assertSame(1, $this->workload($space)->withClient);
        self::assertNull($this->events()->findBySource(self::SOURCE, $itemId));
        self::assertStringNotContainsString('Galette des rois', $this->editorialCalendar());

        // Its address answers like an unknown card.
        $this->post(sprintf('/workspace/%d/content/%d/update', $id, $itemId), ['title' => 'x', 'columnId' => $review->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post(sprintf('/workspace/%d/content/%d/comments', $id, $itemId), ['body' => 'Encore']);
        self::assertResponseStatusCodeSame(404);

        $page = $this->guestPage($guestPath);
        self::assertStringNotContainsString('Galette des rois', $page);
        self::assertStringNotContainsString('Un mot du studio', $page);
        self::assertStringContainsString('Pain au levain', $page);

        $this->client->loginUser($this->admin, 'admin');
        $this->post(sprintf('/suite/studio/space-contents/%d/restore', $itemId));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $restored = $this->items->find($itemId);
        self::assertNotNull($restored);
        self::assertFalse($restored->isTrashed());
        self::assertSame((int) $review->getId(), $restored->getColumn()->getId(), 'back to its own step');
        self::assertSame(2, $this->items->countForSpace($space));
        self::assertNotNull($this->events()->findBySource(self::SOURCE, $itemId), 'its date is back on the calendar');
        self::assertStringContainsString('Galette des rois', $this->guestPage($guestPath));
    }

    /** Its step was deleted while it was in the trash: it comes back to the first one. */
    public function testACardWhoseStepWasDeletedComesBackToTheFirstStep(): void
    {
        $space = $this->givenSpace('Espace sans étape');
        $id = (int) $space->getId();
        $columns = $this->columns->findForSpace($space);
        $itemId = $this->givenItem($id, (int) $columns[1]->getId(), 'Rédigé puis jeté', null);

        $this->post(sprintf('/workspace/%d/content/%d/delete', $id, $itemId));

        // The step holds nothing live any more, so it may go.
        $this->post(sprintf('/workspace/%d/columns/%d/delete', $id, $columns[1]->getId()));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $trashed = $this->items->findTrashed($itemId);
        self::assertNotNull($trashed, 'the cascade of the step did not take it');

        $this->post(sprintf('/suite/studio/space-contents/%d/restore', $itemId));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $restored = $this->items->find($itemId);
        self::assertNotNull($restored);
        self::assertSame((int) $columns[0]->getId(), $restored->getColumn()->getId());
    }

    public function testDeletingForGoodAndThePurgeDestroyIt(): void
    {
        $settings = self::getContainer()->get(SettingRepository::class);
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, '30');

        $space = $this->givenSpace('Espace purgé');
        $id = (int) $space->getId();
        $column = (int) $this->columns->findForSpace($space)[0]->getId();
        $forGood = $this->givenItem($id, $column, 'Détruit au bouton', null);
        $old = $this->givenItem($id, $column, 'Jeté il y a longtemps', null);
        $recent = $this->givenItem($id, $column, 'Jeté hier', null);
        $alive = $this->givenItem($id, $column, 'Toujours là', null);
        $this->post(sprintf('/workspace/%d/content/%d/comments', $id, $forGood), ['body' => 'Emporté avec lui']);

        // Not from the trash's buttons while it is alive.
        $this->post(sprintf('/suite/studio/space-contents/%d/force-delete', $alive));
        self::assertResponseStatusCodeSame(404);

        foreach ([$forGood, $old, $recent] as $itemId) {
            $this->post(sprintf('/workspace/%d/content/%d/delete', $id, $itemId));
        }

        $this->post(sprintf('/suite/studio/space-contents/%d/force-delete', $forGood));
        self::assertResponseIsSuccessful();

        $this->trashedAt($old, new DateTimeImmutable('-45 days'));
        $this->trashedAt($recent, new DateTimeImmutable('-1 day'));
        self::getContainer()->get(PurgeTrashedSpaceContentsHandler::class)(new PurgeTrashedSpaceContentsMessage());

        $this->entityManager->clear();
        self::assertNull($this->items->find($forGood));
        self::assertSame([], $this->entityManager->getRepository(SpaceContentComment::class)->findBy(['item' => $forGood]));
        self::assertNull($this->items->find($old));
        self::assertNotNull($this->items->find($recent));
        self::assertNotNull($this->items->find($alive));
    }

    /**
     * The trash lists the cards of live spaces, naming the space. A card of a
     * space that is itself in the trash is not there: it comes back with it.
     */
    public function testTheTrashScreenListsTheCardsOfLiveSpaces(): void
    {
        $space = $this->givenSpace('Espace vivant');
        $gone = $this->givenSpace('Espace jeté');
        $kept = $this->givenItem((int) $space->getId(), (int) $this->columns->findForSpace($space)[0]->getId(), 'Carte jetée', null);
        $lost = $this->givenItem((int) $gone->getId(), (int) $this->columns->findForSpace($gone)[0]->getId(), 'Carte d\'un espace jeté', null);
        $this->post(sprintf('/workspace/%d/content/%d/delete', $space->getId(), $kept));
        $this->post(sprintf('/workspace/%d/content/%d/delete', $gone->getId(), $lost));
        $this->post(sprintf('/suite/studio/spaces/%d/delete', $gone->getId()));

        $summary = self::getContainer()->get(SpaceContentsTrashSource::class)->getSummary(10);
        self::assertSame(1, $summary->count);
        self::assertSame(['Carte jetée'], array_column($summary->items, 'label'));
        self::assertSame(['Espace vivant'], array_column($summary->items, 'context'));
        self::assertSame('suite_studio_space_contents_restore', $summary->restoreRoute);

        $this->post(sprintf('/suite/studio/space-contents/%d/restore', $lost));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/suite/trash/list');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('studio_space_contents', (string) $this->client->getResponse()->getContent());
    }

    public function testTrashingRestoringAndDestroyingAreInTheAuditLog(): void
    {
        $space = $this->givenSpace('Espace tracé');
        $id = (int) $space->getId();
        $itemId = $this->givenItem($id, (int) $this->columns->findForSpace($space)[0]->getId(), 'Tracé', null);
        $this->post(sprintf('/workspace/%d/content/%d/delete', $id, $itemId));
        $this->post(sprintf('/suite/studio/space-contents/%d/restore', $itemId));
        $this->post(sprintf('/workspace/%d/content/%d/delete', $id, $itemId));
        $this->post(sprintf('/suite/studio/space-contents/%d/force-delete', $itemId));

        $this->entityManager->clear();
        $actions = array_map(
            static fn (AuditLog $log): string => $log->getAction(),
            $this->entityManager->getRepository(AuditLog::class)->findBy(['entityType' => 'SpaceContentItem', 'entityId' => $itemId]),
        );

        self::assertContains('space_content_item.trashed', $actions);
        self::assertContains('space_content_item.restored', $actions);
        self::assertContains('space_content_item.deleted', $actions);
    }

    private function givenSpace(string $name): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client de '.$name)->setContractualEmail('contents@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->post('/suite/studio/spaces/create', [
            'name' => $name,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->find(CustomerSpace::class, $this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenItem(int $spaceId, int $columnId, string $title, ?string $when): int
    {
        $this->post(sprintf('/workspace/%d/content/create', $spaceId), [
            'title' => $title,
            'columnId' => $columnId,
            'scheduledAt' => null === $when ? null : new DateTimeImmutable($when)->format('Y-m-d\TH:i'),
        ]);
        self::assertResponseIsSuccessful();

        $items = $this->json()['items'];

        return (int) $items[array_search($title, array_column($items, 'title'), true)]['id'];
    }

    private function issueLink(int $spaceId): string
    {
        $this->post(sprintf('/workspace/%d/access/issue', $spaceId), [
            'recipientEmail' => 'client@example.test',
            'label' => 'Le client',
        ]);
        self::assertResponseIsSuccessful();

        return (string) parse_url($this->json()['url'], PHP_URL_PATH);
    }

    /** The client page as a guest reads it, entities decoded. */
    private function guestPage(string $path): string
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return html_entity_decode((string) $this->client->getResponse()->getContent());
    }

    private function workload(CustomerSpace $space): SpaceWorkloadRow
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return self::getContainer()->get(SpaceWorkload::class)->forSpace($fresh);
    }

    /** The editorial calendar of every space, for the coming month. */
    private function editorialCalendar(): string
    {
        $this->client->request('GET', sprintf(
            '/suite/studio/spaces/calendar/items?scope=all&from=%s&to=%s',
            new DateTimeImmutable('-1 day')->format('Y-m-d'),
            new DateTimeImmutable('+30 days')->format('Y-m-d'),
        ));
        self::assertResponseIsSuccessful();

        return html_entity_decode((string) $this->client->getResponse()->getContent());
    }

    private function trashedAt(int $id, DateTimeImmutable $at): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s i SET i.deletedAt = :at WHERE i.id = :id', SpaceContentItem::class))
            ->setParameter('at', $at)
            ->setParameter('id', $id)
            ->execute();
    }

    private function events(): PlanningEventRepository
    {
        return self::getContainer()->get(PlanningEventRepository::class);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload = []): void
    {
        $this->client->jsonRequest('POST', $path, $payload);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
