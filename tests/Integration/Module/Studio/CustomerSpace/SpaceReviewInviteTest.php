<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

use function json_decode;
use function sprintf;

/**
 * Asking a client to go and review, and everything the sending must not do.
 *
 * It is the only action in the module that writes to someone's mailbox and
 * closes an address that is still valid. What is checked here comes from
 * those two consequences: that it only goes out when there really is
 * something to review, that it only speaks to those who can answer, and that
 * the address it sends works once the old one has stopped working.
 */
final class SpaceReviewInviteTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkRepository $accessLinkRepository;

    private SpaceContentColumnRepository $columnRepository;

    private SpaceContentItemRepository $itemRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->accessLinkRepository = $container->get(SpaceAccessLinkRepository::class);
        $this->columnRepository = $container->get(SpaceContentColumnRepository::class);
        $this->itemRepository = $container->get(SpaceContentItemRepository::class);

        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        foreach ([SpaceContentComment::class, SpaceContentItem::class, SpaceAccessLink::class, SpaceContentColumn::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheClientIsWrittenToWithAFreshAddress(): void
    {
        $space = $this->givenSpace();
        $this->givenScheduledItem($space, 'À relire');
        $previous = $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload = $this->payload();
        self::assertSame(1, $payload['awaiting']);
        self::assertSame(1, $payload['notified']);

        $mails = $this->mailerMessages();
        self::assertCount(1, $mails);
        self::assertSame('camille@societe.test', $mails[0]->getTo()[0]->getAddress());
        // The number is in the message: it is what decides whether one opens
        // it now or tonight.
        self::assertStringContainsString('1', $mails[0]->getHtmlBody());

        // The old address is closed, and a new one exists for the same
        // person: the client always has exactly one valid address.
        $this->entityManager->clear();
        $stored = $this->accessLinkRepository->find($previous);
        self::assertNotNull($stored->getRevokedAt());

        $live = $this->accessLinkRepository->findApproversForSpace($this->reload($space), new DateTimeImmutable());
        self::assertCount(1, $live);
        self::assertNotSame($previous, $live[0]->getId());
        self::assertSame('camille@societe.test', $live[0]->getRecipientEmail());
    }

    /**
     * An email announcing zero pending publications is the one that teaches
     * people to ignore the next ones.
     */
    public function testNothingIsSentWhenNothingIsWaiting(): void
    {
        $space = $this->givenSpace();
        $previous = $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        $payload = $this->payload();
        self::assertSame(0, $payload['awaiting']);
        self::assertSame(0, $payload['notified']);
        self::assertCount(0, $this->mailerMessages());

        // And above all, the client's address was not closed for nothing.
        $this->entityManager->clear();
        self::assertNull($this->accessLinkRepository->find($previous)->getRevokedAt());
    }

    /**
     * A card without a date, or unticked from the calendar, is not in front of
     * the client: asking them to answer it would be showing them a closed door.
     */
    public function testACardTheClientCannotSeeDoesNotCount(): void
    {
        $space = $this->givenSpace();
        $this->givenItem($space, 'Jamais datée');
        $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        self::assertSame(0, $this->payload()['awaiting']);
        self::assertCount(0, $this->mailerMessages());
    }

    /** A read-only link would receive a request it cannot honour. */
    public function testAReadOnlyLinkIsNotWrittenTo(): void
    {
        $space = $this->givenSpace();
        $this->givenScheduledItem($space, 'À relire');
        $previous = $this->issue($space, canApprove: false);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        $payload = $this->payload();
        // There is something to review, but nobody to ask.
        self::assertSame(1, $payload['awaiting']);
        self::assertSame(0, $payload['notified']);
        self::assertCount(0, $this->mailerMessages());

        $this->entityManager->clear();
        self::assertNull($this->accessLinkRepository->find($previous)->getRevokedAt());
    }

    /**
     * The email first, the revocation after.
     *
     * That is the defect the first local try showed: the mail server was off,
     * the address had already been closed, and the client would have been
     * locked out without having received the one that replaced it.
     */
    public function testAFailedEmailLeavesTheClientTheirAddress(): void
    {
        // Without this the client reboots the kernel on every request and
        // throws the double away with it; and set before any request,
        // otherwise the service is already initialized and can no longer be
        // replaced.
        $this->client->disableReboot();
        static::getContainer()->set('mailer.mailer', new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new RuntimeException('Le serveur de messagerie ne répond pas.');
            }
        });

        $space = $this->givenSpace();
        $this->givenScheduledItem($space, 'À relire');
        $previous = $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // Nobody was notified, and the screen says so rather than announcing a
        // sending that did not happen.
        self::assertSame(0, $this->payload()['notified']);

        $this->entityManager->clear();
        self::assertNull($this->accessLinkRepository->find($previous)->getRevokedAt());
    }

    private function loginAdmin(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    private function issue(CustomerSpace $space, bool $canApprove = true): int
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canApprove' => $canApprove,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->accessLinkRepository->findForSpace($space)[0]->getId();
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client à relancer')
            ->setSiret('73282932000074')
            ->setContractualEmail('review@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace à relire',
            'customerId' => $customer->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function reload(CustomerSpace $space): CustomerSpace
    {
        return $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
    }

    private function givenItem(CustomerSpace $space, string $title): int
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $this->reviewStep($space)->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ($title === $item['title']) {
                return $item['id'];
            }
        }

        self::fail(sprintf('La carte "%s" n\'a pas été créée.', $title));
    }

    private function givenScheduledItem(CustomerSpace $space, string $title): int
    {
        $id = $this->givenItem($space, $title);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/update', $space->getId(), $id), [
            'title' => $title,
            'columnId' => $this->reviewStep($space)->getId(),
            'scheduledAt' => '2026-12-01T09:00',
            'showOnCalendar' => true,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        self::assertNotNull($this->itemRepository->find($id)->getScheduledAt());

        return $id;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * Not `getMailerMessages()`: messenger is enabled, so each sending is
     * reported twice, once queued and once delivered.
     *
     * @return list<Email>
     */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $event) {
            if ($event->isQueued()) {
                continue;
            }

            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $messages[] = $message;
        }

        return $messages;
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
