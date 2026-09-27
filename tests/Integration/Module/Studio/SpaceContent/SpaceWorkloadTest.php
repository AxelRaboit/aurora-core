<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\SpaceContent;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Message\NotifyLateReviewsMessage;
use Aurora\Module\Studio\SpaceContent\MessageHandler\NotifyLateReviewsHandler;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * The five states, each counted once and the same way everywhere.
 *
 * The case that started it: the dashboard said 13 were waiting for the
 * client, the space said 6. Ideas, drafts, published cards, internal steps
 * and archived spaces all counted on one screen and not on the other.
 */
final class SpaceWorkloadTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf("DELETE FROM %s n WHERE n.type = 'studio.space.late_review'", Notification::class))->execute();

        foreach ([SpaceContentItem::class, SpaceContentColumn::class, SpaceAccessLink::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testEachStateCountsWhatItSaysAndNothingElse(): void
    {
        $space = $this->givenSpace('Atelier');
        $review = $this->column($space, SpaceContentColumnRoleEnum::Review);
        $published = $this->column($space, SpaceContentColumnRoleEnum::Published);
        $idea = $this->column($space, SpaceContentColumnRoleEnum::Idea);
        $idea->setVisibleToClient(false);

        $in = static fn (string $when): DateTimeImmutable => new DateTimeImmutable($when);

        // With the client, one of them late; one next week.
        $this->item($space, $review, $in('+2 days'));
        $this->item($space, $review, $in('+10 days'), reviewBy: $in('-1 day'));
        // Not with the client: an internal step, no date, off the calendar,
        // already published.
        $this->item($space, $idea, $in('+3 days'));
        $this->item($space, $review, null);
        $this->item($space, $review, $in('+4 days'), onCalendar: false);
        $this->item($space, $published, $in('-3 days'));
        // Missed: its date passed and it is not published.
        $this->item($space, $review, $in('-2 days'), approval: SpaceContentApprovalEnum::Approved);
        // Came back to the studio.
        $this->item($space, $review, $in('+5 days'), approval: SpaceContentApprovalEnum::ChangesRequested);
        $this->entityManager->flush();

        $row = static::getContainer()->get(SpaceWorkload::class)->forSpace($this->reload($space));

        self::assertSame(2, $row->withClient, 'with the client');
        self::assertSame(1, $row->lateReview, 'late review');
        self::assertSame(1, $row->changesRequested, 'changes requested');
        self::assertSame(1, $row->missed, 'missed');
        self::assertSame(3, $row->upcoming, 'upcoming within a week');
        self::assertSame($in('+2 days')->format('Y-m-d'), $row->nextPublication?->format('Y-m-d'));
    }

    /** Without a step marked « published », nothing can say a date was missed. */
    public function testNothingIsMissedOnABoardWithNoPublishedStep(): void
    {
        $space = $this->givenSpace('Sans étape publiée');

        foreach ($this->entityManager->getRepository(SpaceContentColumn::class)->findBy(['space' => $space]) as $column) {
            $column->setRole(null);
        }

        $this->item($space, $this->column($space, null), new DateTimeImmutable('-2 days'));
        $this->entityManager->flush();

        self::assertSame(0, static::getContainer()->get(SpaceWorkload::class)->forSpace($this->reload($space))->missed);
    }

    public function testAnArchivedSpaceIsLeftOut(): void
    {
        $space = $this->givenSpace('Archivé');
        $this->item($space, $this->column($space, SpaceContentColumnRoleEnum::Review), new DateTimeImmutable('+1 day'));
        $space->setStatus(CustomerSpaceStatusEnum::Archived);
        $this->entityManager->flush();

        self::assertSame([], static::getContainer()->get(SpaceWorkload::class)->forSpaces([$this->reload($space)]));
    }

    /**
     * The studio is told once about what is overdue, on a link that opens
     * those cards, and not again while it has not looked.
     */
    public function testTheTeamIsToldOnceAboutOverdueReviews(): void
    {
        $space = $this->givenSpace('Relances');
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $member = new CustomerSpaceMember();
        $member->setUser($admin)->setRole(CustomerSpaceMemberRoleEnum::Lead);
        $space->addMember($member);
        $this->entityManager->persist($member);
        $this->item($space, $this->column($space, SpaceContentColumnRoleEnum::Review), new DateTimeImmutable('+5 days'), reviewBy: new DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $handler = static::getContainer()->get(NotifyLateReviewsHandler::class);
        $handler(new NotifyLateReviewsMessage());
        $handler(new NotifyLateReviewsMessage());

        $notifications = $this->entityManager->getRepository(Notification::class)->findBy(['type' => 'studio.space.late_review']);
        self::assertCount(1, $notifications, 'not repeated while unread');
        self::assertSame(sprintf('/workspace/%d?state=late_review', $space->getId()), $notifications[0]->getUrl());
        self::assertSame('Une relecture en retard', $notifications[0]->getTitle());
    }

    private function givenSpace(string $name): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client '.$name)->setSiret('73282932000074')->setContractualEmail('charge@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', ['name' => $name, 'customerId' => $customer->getId(), 'timezone' => 'Europe/Paris']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->reload($this->entityManager->getReference(CustomerSpace::class, json_decode((string) $this->client->getResponse()->getContent(), true)['space']['id']));
    }

    private function reload(CustomerSpace $space): CustomerSpace
    {
        $fresh = $this->entityManager->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return $fresh;
    }

    private function column(CustomerSpace $space, ?SpaceContentColumnRoleEnum $role): SpaceContentColumnInterface
    {
        foreach (static::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace($space) as $column) {
            if ($column->getRole() === $role) {
                return $column;
            }
        }

        self::fail('no column with that role');
    }

    private function item(
        CustomerSpace $space,
        SpaceContentColumnInterface $column,
        ?DateTimeImmutable $at,
        ?DateTimeImmutable $reviewBy = null,
        bool $onCalendar = true,
        SpaceContentApprovalEnum $approval = SpaceContentApprovalEnum::Pending,
    ): void {
        $item = new SpaceContentItem();
        $item->setSpace($space)->setColumn($column)->setTitle('Carte')->setScheduledAt($at)->setReviewBy($reviewBy)->setShowOnCalendar($onCalendar);

        if (SpaceContentApprovalEnum::Pending !== $approval) {
            $link = static::getContainer()->get(SpaceAccessLinkManagerInterface::class)->issue($space, 'client@example.test', null, 30, true, true);
            $item->answer($approval, $link, new DateTimeImmutable());
        }

        $this->entityManager->persist($item);
    }
}
