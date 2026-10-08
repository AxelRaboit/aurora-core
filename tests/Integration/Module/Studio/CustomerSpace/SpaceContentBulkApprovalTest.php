<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_filter;
use function array_values;
use function json_decode;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Approving several cards in one action, and the review deadline.
 *
 * Two subjects in one class because they go together: the deadline decides
 * the order in which a client works through their list, and the list is what
 * makes the bulk action possible.
 */
final class SpaceContentBulkApprovalTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columnRepository;

    private SpaceContentItemRepository $itemRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->columnRepository = $container->get(SpaceContentColumnRepository::class);
        $this->itemRepository = $container->get(SpaceContentItemRepository::class);

        $this->resetRateLimiter('space_guest_write');
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf("DELETE FROM %s n WHERE n.type = 'studio.space.answer'", Notification::class))->execute();

        foreach ([SpaceContentComment::class, SpaceContentItem::class, SpaceAccessLink::class, SpaceContentColumn::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testAClientApprovesSeveralAtOnce(): void
    {
        $space = $this->givenSpace();
        $first = $this->givenItem($space, 'Premier');
        $second = $this->givenItem($space, 'Deuxième');
        $untouched = $this->givenItem($space, 'Pas sélectionné');

        $url = $this->issue($space);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$first, $second]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->payload()['approved']);

        $this->entityManager->clear();
        self::assertSame('approved', $this->itemRepository->find($first)->getApproval()->value);
        self::assertSame('approved', $this->itemRepository->find($second)->getApproval()->value);
        // What was not ticked did not move: a bulk action applies to a
        // selection, not to a screen.
        self::assertSame('pending', $this->itemRepository->find($untouched)->getApproval()->value);
    }

    /**
     * Three cards approved in one action: one read, one notification.
     *
     * Each card was read on its own, written on its own, and announced on its
     * own to every team member: twenty cards filed at once made twenty bells.
     */
    public function testSeveralApprovalsAreReadOnceAndAnnouncedOnce(): void
    {
        $space = $this->givenSpace();
        $ids = [$this->givenItem($space, 'Un'), $this->givenItem($space, 'Deux'), $this->givenItem($space, 'Trois')];
        $url = $this->issue($space);

        // Somebody to tell: an administrator who creates a space is not made
        // a member of it, since they see every space anyway.
        $member = new CustomerSpaceMember();
        $member->setUser($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']))->setRole(CustomerSpaceMemberRoleEnum::Lead);
        $managed = $this->entityManager->find(CustomerSpace::class, $space->getId());
        $managed->addMember($member);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->entityManager->createQuery(sprintf("DELETE FROM %s n WHERE n.type = 'studio.space.answer'", Notification::class))->execute();
        $guest = $this->asGuest();
        $guest->disableReboot();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $guest->jsonRequest('POST', $this->approvePath($url), ['ids' => $ids]);
        self::assertSame(3, $this->payload()['approved']);

        $reads = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_starts_with((string) $query['sql'], 'SELECT')
                && str_contains((string) $query['sql'], 'FROM core_studio_space_content_items ')
                && str_contains((string) $query['sql'], ' IN ('),
        );
        self::assertCount(1, $reads, 'the selected cards are read in one query');

        $news = $this->entityManager->getRepository(Notification::class)->findBy(['type' => 'studio.space.answer']);
        self::assertCount(1, $news, 'one piece of news for the whole gesture');
        self::assertStringContainsString('3', $news[0]->getTitle());
    }

    /**
     * The client's page reads its board once.
     *
     * The cards, their threads and their files each read the board again to
     * know what the client is allowed to see: four reads per load, and as many
     * after each answer.
     */
    public function testTheClientPageReadsItsBoardOnce(): void
    {
        $space = $this->givenSpace();
        $this->givenItem($space, 'Un');
        $this->givenItem($space, 'Deux');
        $url = $this->issue($space);

        $guest = $this->asGuest();
        $guest->disableReboot();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $guest->request('GET', (string) parse_url($url, PHP_URL_PATH));
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $boardReads = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_starts_with((string) $query['sql'], 'SELECT')
                && str_contains((string) $query['sql'], 'FROM core_studio_space_content_items '),
        );
        self::assertCount(1, $boardReads, 'the board is read once per page');
    }

    /**
     * The client's page reads its rooms once, with their members.
     *
     * The page and the hub token each read them, and the members of each room
     * came one by one.
     */
    public function testTheClientPageReadsItsRoomsOnce(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space);

        $guest = $this->asGuest();
        $guest->disableReboot();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $guest->request('GET', (string) parse_url($url, PHP_URL_PATH));
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $queries = $holder->getData()['default'] ?? [];
        $roomLists = array_filter($queries, static fn (array $query): bool => str_contains((string) $query['sql'], 'open_to_client = true'));
        $memberLoads = array_filter($queries, static fn (array $query): bool => 1 === preg_match('/FROM core_studio_space_chat_channel_members t0 /', (string) $query['sql']));

        self::assertCount(1, $roomLists, 'the rooms, read once');
        self::assertSame([], array_values($memberLoads), 'no room loads its members alone');
    }

    /** Another client's card is ignored, never approved. */
    public function testACardFromAnotherSpaceIsIgnored(): void
    {
        $space = $this->givenSpace();
        $mine = $this->givenItem($space, 'La mienne');

        $theirs = $this->givenSpace('Autre client', '55217863900132');
        $foreign = $this->givenItem($theirs, 'La leur');

        $url = $this->issue($space);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$mine, $foreign]]);

        self::assertSame(1, $this->payload()['approved']);

        $this->entityManager->clear();
        self::assertSame('approved', $this->itemRepository->find($mine)->getApproval()->value);
        self::assertSame('pending', $this->itemRepository->find($foreign)->getApproval()->value);
    }

    /**
     * A read-only link gets a stranger's 404.
     *
     * Saying "you may read but not answer" would be true and would tell
     * whoever holds a leaked address exactly what they are holding.
     */
    public function testAReadOnlyLinkCannotApproveInBulk(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Interdite');

        $url = $this->issue($space, canApprove: false);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$item]]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        self::assertSame('pending', $this->itemRepository->find($item)->getApproval()->value);
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space);

        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => []]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Lateness is counted on the same three conditions as waiting, plus the
     * passed deadline. A card already approved is late on nothing.
     */
    public function testLatenessNeedsADeadlineAndNoAnswer(): void
    {
        $space = $this->givenSpace();
        $late = $this->givenItem($space, 'En retard');
        $this->schedule($space, $late, '2026-12-01T09:00', '2020-01-01T09:00');

        $onTime = $this->givenItem($space, 'Dans les temps');
        $this->schedule($space, $onTime, '2026-12-01T09:00', '2099-01-01T09:00');

        $noDeadline = $this->givenItem($space, 'Sans échéance');
        $this->schedule($space, $noDeadline, '2026-12-01T09:00', null);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());

        $workload = static::getContainer()->get(SpaceWorkload::class);
        self::assertSame(3, $workload->forSpace($stored)->withClient);
        self::assertSame(1, $workload->forSpace($stored)->lateReview);

        // Once answered, it is no longer late without its deadline having moved.
        $url = $this->issue($space);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$late]]);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertSame(0, $workload->forSpace($stored)->lateReview);
    }

    private function loginAdmin(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        return $this->client;
    }

    private function issue(CustomerSpace $space, bool $canApprove = true): string
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canApprove' => $canApprove,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->payload()['url'];
    }

    private function approvePath(string $url): string
    {
        return sprintf('%s/content/approve', (string) parse_url($url, PHP_URL_PATH));
    }

    private function givenSpace(string $customerName = 'Client en lot', string $siret = '73282932000074'): CustomerSpace
    {
        $this->loginAdmin();

        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('bulk@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace de '.$customerName,
            'customerId' => $customer->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenItem(CustomerSpace $space, string $title): int
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $this->reviewStep($space)->getId(),
            // Dated: the client's page only shows its calendar, and only
            // accepts a verdict on what it shows.
            'scheduledAt' => '2026-12-01T10:00',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ($title === $item['title']) {
                return $item['id'];
            }
        }

        self::fail(sprintf('La carte "%s" n\'a pas été créée.', $title));
    }

    private function schedule(CustomerSpace $space, int $itemId, string $scheduledAt, ?string $reviewBy): void
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/update', $space->getId(), $itemId), [
            'title' => $this->itemRepository->find($itemId)->getTitle(),
            'columnId' => $this->reviewStep($space)->getId(),
            'scheduledAt' => $scheduledAt,
            'reviewBy' => $reviewBy,
            'showOnCalendar' => true,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** The step where the client answers: the one cards awaiting a review sit on. */
    private function reviewStep(CustomerSpace $space): SpaceContentColumnInterface
    {
        foreach ($this->columnRepository->findForSpace($space) as $column) {
            if (SpaceContentColumnRoleEnum::Review === $column->getRole()) {
                return $column;
            }
        }

        self::fail('The default board has a Review step.');
    }
}
