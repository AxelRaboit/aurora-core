<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\ClientNotice\Entity\ClientNotice;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatReadMarker;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatReadMarkerRepository;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function parse_url;
use function sprintf;

use const PHP_URL_PATH;

/**
 * What is new in a conversation, for each side.
 *
 * A conversation had no read state: the studio heard of a client's message
 * from the bell and then had to find it, and a client could not tell the
 * team had answered. A mark per reader and room now says what was read.
 */
final class SpaceChatUnreadTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private User $admin;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
        $this->resetRateLimiter('space_guest_write');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceChatReadMarker::class, ClientNotice::class, SpaceChatMessage::class, SpaceAccessLink::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testAClientMessageIsUnreadUntilTheRoomIsOpened(): void
    {
        [$space, $url] = $this->givenLinkedSpace();
        $channel = $this->mainChannel($space);

        $this->client->jsonRequest('POST', $url.'/chat/'.$channel, ['body' => 'Une question.']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->unreadForStudio($space));

        // The room on the studio's screen: read.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d/read', $space, $channel));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->unreadForStudio($space));
    }

    /**
     * The studio's answer is new for the client, never for its author; the
     * client's own message is never new for the client.
     */
    public function testEachSideCountsWhatTheOtherWrote(): void
    {
        [$space, $url] = $this->givenLinkedSpace();
        $channel = $this->mainChannel($space);
        $link = $this->linkOf($url);

        $this->client->jsonRequest('POST', $url.'/chat/'.$channel, ['body' => 'Bonjour.']);
        // Marks and messages are stored to the second: the answer must come
        // after the client's own mark, as it does outside a test.
        $this->entityManager->getConnection()->executeStatement("UPDATE core_studio_space_chat_read_markers SET read_at = read_at - INTERVAL '1 minute'");
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space, $channel), ['body' => 'Bonjour, on regarde.']);

        self::assertSame(0, $this->unreadForStudio($space), 'Answering is reading.');
        self::assertSame(1, $this->unreadForLink($space, $link));

        $this->client->jsonRequest('POST', $url.'/chat/'.$channel.'/read');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->unreadForLink($space, $link));
    }

    /** The counts reach the page: the rooms and the tab of the space. */
    public function testTheClientPageCarriesItsCount(): void
    {
        [$space, $url] = $this->givenLinkedSpace();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d', $space, $this->mainChannel($space)), ['body' => 'Le planning est prêt.']);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('&quot;chatUnread&quot;:1', (string) $this->client->getResponse()->getContent());
    }

    private function unreadForStudio(int $space): int
    {
        $this->entityManager->clear();
        $rooms = static::getContainer()->get(SpaceChatChannelRepository::class)->findForUser($this->entityManager->find(CustomerSpace::class, $space), $this->entityManager->find(User::class, $this->admin->getId()));

        return static::getContainer()->get(SpaceChatReadMarkerRepository::class)->totalUnread($rooms, $this->entityManager->find(User::class, $this->admin->getId()), null);
    }

    private function unreadForLink(int $space, SpaceAccessLinkInterface $link): int
    {
        $this->entityManager->clear();
        $link = $this->entityManager->find(SpaceAccessLink::class, $link->getId());
        $rooms = static::getContainer()->get(SpaceChatChannelRepository::class)->findForLink($this->entityManager->find(CustomerSpace::class, $space), $link);

        return static::getContainer()->get(SpaceChatReadMarkerRepository::class)->totalUnread($rooms, null, $link);
    }

    /** @return array{0: int, 1: string} */
    private function givenLinkedSpace(): array
    {
        $customer = new Customer();
        $customer->setLegalName('Client bavard')->setSiret('73282932000074')->setContractualEmail('chat@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', ['name' => 'Espace bavard', 'customerId' => $customer->getId()]);
        $space = (int) $this->payload()['space']['id'];

        $member = new CustomerSpaceMember();
        $member
            ->setSpace($this->entityManager->getReference(CustomerSpace::class, $space))
            ->setUser($this->entityManager->getReference(User::class, $this->admin->getId()))
            ->setRole(CustomerSpaceMemberRoleEnum::Lead);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille',
            'canChat' => true,
        ]);

        return [$space, (string) parse_url($this->payload()['url'], PHP_URL_PATH)];
    }

    private function linkOf(string $url): SpaceAccessLinkInterface
    {
        $parts = explode('/', mb_trim($url, '/'));
        $link = static::getContainer()->get(SpaceAccessLinkRepository::class)->findBySelector($parts[count($parts) - 2]);
        self::assertInstanceOf(SpaceAccessLinkInterface::class, $link);

        return $link;
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
}
