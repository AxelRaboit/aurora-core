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
use Aurora\Module\Studio\CustomerSpace\Message\SpaceActivityDigestMessage;
use Aurora\Module\Studio\CustomerSpace\MessageHandler\SpaceActivityDigestHandler;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

use function json_decode;
use function sprintf;

/**
 * A space's own conversation, end to end.
 *
 * Weighted towards the four things that are not obvious from the code: that a
 * client may write into it at all, that the studio is told when they do and
 * told once rather than once per message, that the studio cannot delete what a
 * client said, and that the whole thing works with no hub running - which is
 * how it will run on most installations and is the reason it is built on stored
 * messages rather than on the hub.
 */
final class SpaceChatTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);

        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s n WHERE n.type LIKE :prefix', Notification::class)
        )->setParameter('prefix', 'studio.space.%')->execute();

        foreach ([
            SpaceChatMessage::class,
            SpaceAccessLink::class,
            CustomerSpaceMember::class,
            CustomerSpace::class,
            Customer::class,
        ] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheStudioWritesAndReadsItBack(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space->getId(), $this->mainChannel($space)), [
            'body' => "Le brief d'octobre est prêt.",
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $messages = $this->payload()['chatMessages'];
        self::assertCount(1, $messages);
        self::assertSame("Le brief d'octobre est prêt.", $messages[0]['body']);
        self::assertFalse($messages[0]['fromClient']);
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space->getId(), $this->mainChannel($space)), ['body' => '   ']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('body', $this->payload()['errors']);
    }

    /**
     * **The guarantee that makes the hub optional.**.
     *
     * No `MERCURE_URL` in the test environment, so the page is handed no
     * address to connect to - which is what tells the panel to fall back on
     * asking - and every write still answers with the conversation. If this
     * ever fails, the chat has quietly become something that only works where
     * a hub is installed.
     */
    public function testItWorksWithNoHubConfigured(): void
    {
        $space = $this->givenSpace();

        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space->getId(), $this->mainChannel($space)), ['body' => 'Sans hub.']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', sprintf('/workspace/%d/chat/%d/messages', $space->getId(), $this->mainChannel($space)));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->payload()['chatMessages']);
    }

    public function testAClientWritesThroughTheirLink(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canComment: true);

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Une question sur le visuel.']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $messages = $this->payload()['chatMessages'];
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]['fromClient']);
        self::assertSame('Camille, gérante', $messages[0]['author']);

        // And the studio reads the same row, because it is one conversation.
        $this->client->request('GET', sprintf('/workspace/%d/chat/%d/messages', $space->getId(), $this->mainChannel($space)));
        self::assertCount(1, $this->payload()['chatMessages']);
    }

    /**
     * A link that may not comment may not write here either, and is told
     * nothing: the same 404 a stranger gets, so a leaked address does not
     * reveal what it holds.
     */
    public function testALinkThatMayNotCommentIsRefused(): void
    {
        [, $url] = $this->givenLinkedSpace(canComment: false);

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Bonjour ?']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheStudioIsToldOnceWhateverTheNumberOfMessages(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canComment: true);
        $this->givenMember($space);

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Premier message.']);
        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Et un deuxième.']);
        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Et un troisième.']);

        // Three messages, one thing to go and look at. Folding them is what
        // keeps a bell worth reading.
        $notifications = $this->entityManager->getRepository(Notification::class)
            ->findBy(['recipient' => $this->admin, 'type' => 'studio.space.chat']);

        self::assertCount(1, $notifications);
        self::assertSame('Camille, gérante vous a écrit', $notifications[0]->getTitle());
        self::assertSame($space->getName(), $notifications[0]->getBody());
    }

    /**
     * The studio writing does not notify the studio.
     *
     * What is announced is the thing that happens while nobody is looking.
     */
    public function testTheStudioIsNotToldAboutItself(): void
    {
        $space = $this->givenSpace();
        $this->givenMember($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space->getId(), $this->mainChannel($space)), ['body' => 'À nous-mêmes.']);

        self::assertCount(0, $this->entityManager->getRepository(Notification::class)
            ->findBy(['recipient' => $this->admin, 'type' => 'studio.space.chat']));
    }

    public function testTheStudioCannotDeleteWhatTheClientSaid(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canComment: true);

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Ce contenu ne me va pas.']);
        $messageId = $this->payload()['chatMessages'][0]['id'];

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/%d/%d/delete', $space->getId(), $this->mainChannel($space), $messageId),
        );

        // Refused under the field, not silently ignored: a provider able to
        // delete a customer's complaint has a record that proves nothing.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('message', $this->payload()['errors']);
    }

    public function testTheStudioRemovesItsOwnMessage(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space->getId(), $this->mainChannel($space)), ['body' => 'Oubliez ça.']);
        $messageId = $this->payload()['chatMessages'][0]['id'];

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/%d/%d/delete', $space->getId(), $this->mainChannel($space), $messageId),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->payload()['chatMessages']);
    }

    /**
     * One space cannot reach into another's conversation.
     *
     * The message arrives as its own entity through the URL, so nothing but
     * this check stands between two clients' spaces.
     */
    public function testAMessageOfAnotherSpaceIsOutOfReach(): void
    {
        $mine = $this->givenSpace('Client A', '73282932000074');
        $theirs = $this->givenSpace('Client B', '55203534400028');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $theirs->getId(), $this->mainChannel($theirs)), ['body' => 'Chez eux.']);
        $messageId = $this->payload()['chatMessages'][0]['id'];

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/%d/%d/delete', $mine->getId(), $this->mainChannel($mine), $messageId),
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * **The delay is the feature.** Somebody at their desk answers before their
     * mailbox is told, so the second look finds nothing left to say.
     */
    public function testNoEmailWhenTheNewsHasAlreadyBeenRead(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canComment: true);
        $this->givenMember($space);

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Une question.']);

        $this->markEverythingRead();
        $this->runDigest($space);

        self::assertCount(0, $this->mailerMessages());
    }

    public function testOneEmailWhateverTheNumberOfMessages(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canComment: true);
        $this->givenMember($space);

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Premier.']);
        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Deuxième.']);

        $this->runDigest($space);

        $mails = $this->mailerMessages();
        self::assertCount(1, $mails);
        self::assertStringContainsString($space->getName(), (string) $mails[0]->getSubject());
    }

    /**
     * And then silence, which is the half people actually notice.
     *
     * A mailbox that has been told once does not need telling again while the
     * person still has not come back. Reading is what re-arms it.
     */
    public function testNoSecondEmailUntilTheyHaveComeBack(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canComment: true);
        $this->givenMember($space);

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Premier.']);
        $this->runDigest($space);
        self::assertCount(1, $this->mailerMessages());

        // Something else happens while they are still away. Counted per phase
        // rather than cumulatively: the mailer collector is cleared by each
        // request, so what this reads is "did that round send anything".
        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Toujours là ?']);
        $this->runDigest($space);
        self::assertCount(0, $this->mailerMessages());

        // They open the space, and the next thing that happens is worth a mail
        // again.
        $this->markEverythingRead();
        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannelOfLink($url), ['body' => 'Et une relance.']);
        $this->runDigest($space);

        self::assertCount(1, $this->mailerMessages());
    }

    /**
     * Runs the second look that would otherwise happen five minutes later.
     *
     * Called directly rather than by draining a transport: what is under test
     * is what the handler decides, and a test that waited for a delay stamp
     * would be testing Messenger.
     */
    private function runDigest(CustomerSpace $space): void
    {
        static::getContainer()->get(SpaceActivityDigestHandler::class)(
            new SpaceActivityDigestMessage((int) $this->admin->getId(), (int) $space->getId()),
        );
    }

    private function markEverythingRead(): void
    {
        $this->entityManager->createQuery(
            sprintf('UPDATE %s n SET n.readAt = :now WHERE n.readAt IS NULL', Notification::class)
        )->setParameter('now', new DateTimeImmutable())->execute();

        $this->entityManager->clear();
    }

    /**
     * The mails that actually reached a transport during the last round.
     *
     * Cleared by every request, so this counts one phase of a test rather than
     * everything it has sent - which is why the sequences below assert after
     * each step instead of on a running total.
     *
     * Not `getMailerMessages()`: messenger is enabled, so each mail is
     * dispatched twice - queued, then unqueued when the handler runs - and
     * counting both reads one email as two.
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

    /**
     * The room a space was born with.
     *
     * Every address the conversation answers on names a room since rooms
     * exist, and the one every space has is the one these tests write in.
     */
    private function mainChannel(CustomerSpace $space): int
    {
        $channel = static::getContainer()->get(SpaceChatChannelRepository::class)->findMain(
            $this->entityManager->getReference(CustomerSpace::class, $space->getId()),
        );

        self::assertInstanceOf(SpaceChatChannelInterface::class, $channel);

        return (int) $channel->getId();
    }

    /**
     * The same room, reached from the client's address.
     *
     * The public page names its space by a link rather than by an id, so the
     * link is resolved the way the controller does before the room is asked
     * for.
     */
    private function mainChannelOfLink(string $url): int
    {
        $parts = explode('/', mb_trim(parse_url($url, PHP_URL_PATH) ?? '', '/'));
        $selector = $parts[count($parts) - 2] ?? '';

        $link = static::getContainer()->get(SpaceAccessLinkRepository::class)->findBySelector($selector);
        self::assertInstanceOf(SpaceAccessLinkInterface::class, $link);

        $channel = static::getContainer()->get(SpaceChatChannelRepository::class)->findMain($link->getSpace());
        self::assertInstanceOf(SpaceChatChannelInterface::class, $channel);

        return (int) $channel->getId();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * Puts the signed-in account on the space, which is what subscribes it.
     *
     * Both sides are looked up again through this manager: the request that
     * created the space ran in its own kernel, and the objects this test is
     * holding are detached by the time we get here.
     */
    private function givenMember(CustomerSpace $space): void
    {
        $member = new CustomerSpaceMember();
        $member
            ->setSpace($this->entityManager->getReference(CustomerSpace::class, $space->getId()))
            ->setUser($this->entityManager->getReference(User::class, $this->admin->getId()))
            ->setRole(CustomerSpaceMemberRoleEnum::Lead);

        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    /** @return array{0: CustomerSpace, 1: string} */
    private function givenLinkedSpace(bool $canComment): array
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canComment' => $canComment,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return [$space, $this->payload()['url']];
    }

    private function givenSpace(
        string $customerName = 'Client de la discussion',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('chat@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace de '.$customerName,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
