<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\ClientNotice\Entity\ClientNotice;
use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\ClientNotice\Message\ClientDigestMessage;
use Aurora\Module\Studio\ClientNotice\Message\RemindClientReviewDeadlinesMessage;
use Aurora\Module\Studio\ClientNotice\Message\SendDailyClientDigestsMessage;
use Aurora\Module\Studio\ClientNotice\MessageHandler\ClientDigestHandler;
use Aurora\Module\Studio\ClientNotice\MessageHandler\RemindClientReviewDeadlinesHandler;
use Aurora\Module\Studio\ClientNotice\MessageHandler\SendDailyClientDigestsHandler;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Enum\ClientDigestModeEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

use function array_map;
use function json_decode;
use function sprintf;

/**
 * What the studio does, told to the client.
 *
 * Until 4.12 nothing was: a message from the team, a content to approve, a
 * file shared reached the client the day they happened to open their page.
 * Notices are now written for every link the news concerns, shown at the top
 * of the client's page, and mailed when the space chose to.
 */
final class ClientNoticesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([ClientNotice::class, SpaceChatMessage::class, SpaceContentItem::class, SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    /**
     * A message in a room the client reads is written for the links that may
     * chat, and half an hour later one mail goes out, in the customer's
     * language. The next look sends nothing.
     */
    public function testAStudioMessageReachesTheClientInOneDelayedMail(): void
    {
        $space = $this->givenSpace(ClientDigestModeEnum::Delayed, 'en');
        $chatter = $this->givenLink($space, 'camille@societe.test', canChat: true);
        $this->givenLink($space, 'lecteur@societe.test', canChat: false);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space, $this->mainChannel($space)), ['body' => 'Le planning est prêt.']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $notices = $this->notices();
        self::assertCount(1, $notices, 'Only the link that may chat is told.');
        self::assertSame(ClientNoticeTypeEnum::StudioMessage, $notices[0]->getType());

        // Too early: the batch may not be finished.
        $this->runDigest($chatter);
        self::assertCount(0, $this->mailerMessages());

        $this->ageNotices('-31 minutes');
        $this->runDigest($chatter);

        $mails = $this->mailerMessages();
        self::assertCount(1, $mails);
        self::assertSame('camille@societe.test', $mails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Something new in your', (string) $mails[0]->getSubject());
        self::assertStringContainsString('new message from', (string) $mails[0]->getHtmlBody());

        $this->runDigest($chatter);
        self::assertCount(1, $this->mailerMessages(), 'Never twice.');
    }

    /** A space that mails nothing still writes the news: the page shows it. */
    public function testASpaceThatMailsNothingStillShowsTheNewsOnThePage(): void
    {
        $space = $this->givenSpace(ClientDigestModeEnum::Off);
        $link = $this->givenLink($space, 'camille@societe.test', canChat: true);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space, $this->mainChannel($space)), ['body' => 'Bonjour.']);
        $this->ageNotices('-31 minutes');
        $this->runDigest($link);
        self::assertCount(0, $this->mailerMessages());

        // The page lists it, and opening it makes it seen.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->urlOf($link));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('client_notices.lines.studio_message', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        self::assertNotNull($this->notices()[0]->getSeenAt());
    }

    /**
     * A card dragged into « À valider » is told to who may answer, and only to
     * them. What the client saw on their page is not mailed afterwards.
     */
    public function testACardReachingTheReviewStepIsToldToApproversOnly(): void
    {
        $space = $this->givenSpace(ClientDigestModeEnum::Delayed);
        $approver = $this->givenLink($space, 'camille@societe.test', canApprove: true);
        $this->givenLink($space, 'lecteur@societe.test', canApprove: false);

        $columns = static::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace($this->entityManager->find(CustomerSpace::class, $space));
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space), [
            'title' => 'Portes ouvertes',
            'columnId' => $columns[1]->getId(),
            'scheduledAt' => '2026-12-01T10:00',
        ]);
        $itemId = $this->payload()['items'][0]['id'];
        self::assertCount(0, $this->notices(), 'In « Rédaction », the client does not see it.');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/reorder', $space), [
            'columnId' => $columns[2]->getId(),
            'itemIds' => [$itemId],
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $notices = $this->notices();
        self::assertCount(1, $notices);
        self::assertSame(ClientNoticeTypeEnum::AwaitingReview, $notices[0]->getType());
        self::assertSame('Portes ouvertes', $notices[0]->getSubject());

        // Seen on the page before the half hour: nothing is mailed.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->urlOf($approver));
        $this->ageNotices('-31 minutes');
        $this->runDigest($approver);
        self::assertCount(0, $this->mailerMessages());
    }

    /** The morning digest goes to the spaces that chose it, and to them only. */
    public function testTheMorningDigestOnlyWritesForDailySpaces(): void
    {
        $daily = $this->givenSpace(ClientDigestModeEnum::Daily, siret: '73282932000074');
        $this->givenLink($daily, 'matin@societe.test', canChat: true);
        $delayed = $this->givenSpace(ClientDigestModeEnum::Delayed, siret: '44306184100047');
        $this->givenLink($delayed, 'delai@societe.test', canChat: true);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $daily, $this->mainChannel($daily)), ['body' => 'Un.']);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $delayed, $this->mainChannel($delayed)), ['body' => 'Deux.']);

        static::getContainer()->get(SendDailyClientDigestsHandler::class)(new SendDailyClientDigestsMessage());

        $recipients = array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), $this->mailerMessages());
        self::assertSame(['matin@societe.test'], $recipients);
    }

    /** The day before a review is due, who may answer hears of it. */
    public function testAReviewDueTomorrowIsToldTheDayBefore(): void
    {
        $space = $this->givenSpace(ClientDigestModeEnum::Daily);
        $this->givenLink($space, 'camille@societe.test', canApprove: true);

        $columns = static::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace($this->entityManager->find(CustomerSpace::class, $space));
        $tomorrow = new DateTimeImmutable('tomorrow 15:00', new DateTimeZone('Europe/Paris'));
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space), [
            'title' => 'Jeu concours',
            'columnId' => $columns[2]->getId(),
            'scheduledAt' => $tomorrow->modify('+3 days')->format('Y-m-d\TH:i'),
            'reviewBy' => $tomorrow->format('Y-m-d\TH:i'),
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $handler = static::getContainer()->get(RemindClientReviewDeadlinesHandler::class);
        $handler(new RemindClientReviewDeadlinesMessage());
        $handler(new RemindClientReviewDeadlinesMessage());

        $due = array_values(array_filter($this->notices(), static fn (ClientNotice $notice): bool => ClientNoticeTypeEnum::ReviewDue === $notice->getType()));
        self::assertCount(1, $due, 'Once per content, however often the job runs.');
        self::assertSame('Jeu concours', $due[0]->getSubject());
    }

    private function givenSpace(ClientDigestModeEnum $mode, ?string $locale = null, string $siret = '73282932000074'): int
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client prévenu '.$siret)
            ->setSiret($siret)
            ->setContractualEmail('client@example.test')
            ->setLocale($locale);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace prévenu '.$siret,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $id = (int) $this->payload()['space']['id'];

        $this->entityManager->createQuery(sprintf('UPDATE %s s SET s.clientDigest = :mode WHERE s.id = :id', CustomerSpace::class))
            ->setParameter('mode', $mode)
            ->setParameter('id', $id)
            ->execute();

        return $id;
    }

    /** @return array{id: int, url: string} */
    private function givenLink(int $space, string $email, bool $canChat = false, bool $canApprove = false): array
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space), [
            'recipientEmail' => $email,
            'label' => $email,
            'canApprove' => $canApprove,
            'canComment' => false,
            'canChat' => $canChat,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $url = (string) $this->payload()['url'];

        $parts = explode('/', mb_trim((string) parse_url($url, PHP_URL_PATH), '/'));
        $link = static::getContainer()->get(SpaceAccessLinkRepository::class)->findBySelector($parts[count($parts) - 2]);
        self::assertNotNull($link);

        return ['id' => (int) $link->getId(), 'url' => (string) parse_url($url, PHP_URL_PATH)];
    }

    /** @param array{id: int, url: string} $link */
    private function urlOf(array $link): string
    {
        return $link['url'];
    }

    /** @param array{id: int, url: string} $link */
    private function runDigest(array $link): void
    {
        static::getContainer()->get(ClientDigestHandler::class)(new ClientDigestMessage($link['id']));
    }

    private function ageNotices(string $by): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s n SET n.createdAt = :at', ClientNotice::class))
            ->setParameter('at', new DateTimeImmutable($by), Types::DATETIME_IMMUTABLE)
            ->execute();
        $this->entityManager->clear();
        // The handlers run in the container the last request left behind,
        // whose manager may hold the notice as it was before.
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** @return list<ClientNotice> */
    private function notices(): array
    {
        return $this->entityManager->getRepository(ClientNotice::class)->findBy([], ['id' => 'ASC']);
    }

    private function mainChannel(int $space): int
    {
        $channel = static::getContainer()->get(SpaceChatChannelRepository::class)->findMain($this->entityManager->getReference(CustomerSpace::class, $space));
        self::assertInstanceOf(SpaceChatChannelInterface::class, $channel);

        return (int) $channel->getId();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return list<Email> */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $event) {
            if (!$event->isQueued()) {
                $message = $event->getMessage();
                self::assertInstanceOf(Email::class, $message);
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
