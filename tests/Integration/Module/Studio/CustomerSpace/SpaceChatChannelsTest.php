<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannel;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * Rooms inside a space's conversation.
 *
 * The assertions are about the one thing rooms were added for and the one thing
 * they could get catastrophically wrong: the studio gets somewhere to talk
 * among itself, and what is said there does not reach the customer. A room that
 * leaked would be worse than no rooms at all, because everybody would be
 * writing in it believing the opposite.
 */
final class SpaceChatChannelsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

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
        // Le navigateur pose cet en-tête sur chaque appel, et les routes
        // publiques l'exigent : ce qui les protège est un secret dans
        // l'adresse, et une adresse se transfère.
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s u WHERE u.email LIKE :suffix', User::class)
        )->setParameter('suffix', '%@aurora.test')->execute();

        foreach ([
            SpaceChatMessage::class,
            SpaceChatChannel::class,
            SpaceAccessLink::class,
            CustomerSpaceMember::class,
            CustomerSpace::class,
            Customer::class,
        ] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testASpaceIsBornWithOneRoomTheClientReads(): void
    {
        $space = $this->givenSpace();

        $main = $this->mainChannel($space);

        self::assertTrue($main->isOpenToClient(), 'The room a space is born with is the conversation it already had.');
        self::assertSame('main', $main->getKind()->value);
    }

    public function testARoomOpenedByTheStudioIsInternalUntilItIsOpened(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/create', $space->getId()),
            ['name' => 'Entre nous'],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $rooms = $this->payload()['chatChannels'];
        $opened = $this->named($rooms, 'Entre nous');

        self::assertFalse($opened['openToClient'], 'A room is closed to the client until somebody says otherwise.');
        // Whoever opened it is in it, or it would vanish from their own list.
        self::assertCount(1, $opened['members']);
    }

    public function testTheClientIsHandedTheOpenRoomsAndNotTheOthers(): void
    {
        [$space, $url] = $this->givenLinkedSpace();

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/create', $space->getId()),
            ['name' => 'Notes internes'],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $internal = $this->named($this->payload()['chatChannels'], 'Notes internes');

        // The client's own page, read the way a client reads it.
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('Notes internes', $html, 'An internal room must not be named on the page a customer reads.');

        // And asking for it by id is a 404, not a refusal: telling somebody a
        // room exists is already telling them something.
        $this->client->request('GET', sprintf('%s/chat/%d/messages', $url, $internal['id']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnOpenedRoomReachesTheClient(): void
    {
        [$space, $url] = $this->givenLinkedSpace();

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/create', $space->getId()),
            ['name' => 'Le mois prochain', 'openToClient' => true],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $room = $this->named($this->payload()['chatChannels'], 'Le mois prochain');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/%d', $space->getId(), $room['id']),
            ['body' => 'On décale la campagne.'],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', sprintf('%s/chat/%d/messages', $url, $room['id']));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('On décale la campagne.', $this->payload()['chatMessages'][0]['body']);
    }

    public function testTheMainRoomCannotBeClosedOrDeleted(): void
    {
        $space = $this->givenSpace();
        $main = $this->mainChannel($space);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/%d/audience', $space->getId(), $main->getId()),
            ['openToClient' => false],
        );
        self::assertArrayHasKey('errors', $this->payload());

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/%d/delete', $space->getId(), $main->getId()),
        );
        self::assertArrayHasKey('errors', $this->payload());

        $this->entityManager->clear();
        self::assertTrue($this->mainChannel($space)->isOpenToClient());
    }

    public function testARoomOfAnotherSpaceIsNotReachableUnderThisOne(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace('Un autre client', '73282932000082');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/%d', $mine->getId(), $this->mainChannel($theirs)->getId()),
            ['body' => 'Chez eux, sous mon adresse.'],
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAPrivateConversationIsOpenedOnceAndNamedByTheOtherPerson(): void
    {
        $space = $this->givenSpace();
        $mate = $this->givenTeammate($space);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/direct', $space->getId()),
            ['userId' => $mate->getId()],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $first = $this->payload();
        $direct = $this->named($first['chatChannels'], 'Camille Martin');

        self::assertTrue($direct['isDirect']);
        self::assertFalse($direct['openToClient'], 'A private conversation is not something the client reads.');
        self::assertCount(2, $direct['members']);
        // Nommée par l'autre personne, jamais par soi-même.
        self::assertSame('Camille Martin', $direct['name']);

        // Deux personnes n'ont qu'une conversation : redemander rouvre la même.
        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/direct', $space->getId()),
            ['userId' => $mate->getId()],
        );

        self::assertSame($first['chatChannelId'], $this->payload()['chatChannelId']);
    }

    public function testAPrivateConversationIsRefusedWithSomebodyOutsideTheSpace(): void
    {
        $space = $this->givenSpace();
        $stranger = $this->givenAccount('etranger@aurora.test', 'Étranger');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/direct', $space->getId()),
            ['userId' => $stranger->getId()],
        );

        self::assertArrayHasKey('errors', $this->payload());
    }

    public function testAConversationPutAwayLeavesOneListAndKeepsEverything(): void
    {
        $space = $this->givenSpace();
        $mate = $this->givenTeammate($space);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/direct', $space->getId()),
            ['userId' => $mate->getId()],
        );
        $channelId = $this->payload()['chatChannelId'];

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/%d', $space->getId(), $channelId),
            ['body' => 'Je te redis demain.'],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Rangée : elle quitte ma liste.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/%d/hide', $space->getId(), $channelId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $names = array_column($this->payload()['chatChannels'], 'name');
        self::assertNotContains('Camille Martin', $names);

        // Rien n'a été effacé : le salon et son message sont toujours là.
        $this->entityManager->clear();
        $channel = $this->entityManager->find(SpaceChatChannel::class, $channelId);
        self::assertInstanceOf(SpaceChatChannel::class, $channel);

        // Rouvrir avec la même personne rend la conversation, et son historique.
        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/direct', $space->getId()),
            ['userId' => $mate->getId()],
        );

        self::assertSame($channelId, $this->payload()['chatChannelId'], 'Reopening finds the conversation, it does not start a second one.');

        $this->client->request('GET', sprintf('/workspace/%d/chat/%d/messages', $space->getId(), $channelId));
        self::assertSame('Je te redis demain.', $this->payload()['chatMessages'][0]['body']);
    }

    public function testSomebodyInvitedIntoARoomCanBeTakenOutOfItAgain(): void
    {
        $space = $this->givenSpace();
        $mate = $this->givenTeammate($space);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/create', $space->getId()),
            ['name' => 'Le mois prochain'],
        );
        $room = $this->named($this->payload()['chatChannels'], 'Le mois prochain');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/%d/invite', $space->getId(), $room['id']),
            ['userId' => $mate->getId()],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $invited = $this->named($this->payload()['chatChannels'], 'Le mois prochain');
        $labels = array_column($invited['members'], 'label');
        self::assertContains('Camille Martin', $labels);

        $member = $this->memberNamed($invited, 'Camille Martin');

        // Ce qu'elle a écrit reste : retirer quelqu'un range une liste, ça
        // n'efface pas une conversation.
        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/%d', $space->getId(), $room['id']),
            ['body' => 'Je prends le sujet de septembre.'],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/%d/members/%d/remove', $space->getId(), $room['id'], $member['id']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $after = $this->named($this->payload()['chatChannels'], 'Le mois prochain');
        self::assertNotContains('Camille Martin', array_column($after['members'], 'label'));

        $this->client->request('GET', sprintf('/workspace/%d/chat/%d/messages', $space->getId(), $room['id']));
        self::assertSame('Je prends le sujet de septembre.', $this->payload()['chatMessages'][0]['body']);
    }

    public function testAPrivateConversationKeepsBothOfItsPeople(): void
    {
        $space = $this->givenSpace();
        $mate = $this->givenTeammate($space);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/direct', $space->getId()),
            ['userId' => $mate->getId()],
        );
        $channelId = $this->payload()['chatChannelId'];
        $direct = $this->named($this->payload()['chatChannels'], 'Camille Martin');
        $member = $this->memberNamed($direct, 'Camille Martin');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/%d/members/%d/remove', $space->getId(), $channelId, $member['id']),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'A conversation with one person in it is not a conversation.');
    }

    public function testAMemberOfAnotherRoomIsNotRemovableUnderThisOne(): void
    {
        $space = $this->givenSpace();
        $mate = $this->givenTeammate($space);

        foreach (['Un', 'Deux'] as $name) {
            $this->client->jsonRequest(
                'POST',
                sprintf('/workspace/%d/chat/channels/create', $space->getId()),
                ['name' => $name],
            );
        }

        $rooms = $this->payload()['chatChannels'];
        $first = $this->named($rooms, 'Un');
        $second = $this->named($rooms, 'Deux');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/%d/invite', $space->getId(), $first['id']),
            ['userId' => $mate->getId()],
        );

        $member = $this->memberNamed($this->named($this->payload()['chatChannels'], 'Un'), 'Camille Martin');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/chat/channels/%d/members/%d/remove', $space->getId(), $second['id'], $member['id']),
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheHistoryComesBackFromTheMessageItIsAskedFrom(): void
    {
        $space = $this->givenSpace();
        $main = $this->mainChannel($space);

        for ($index = 1; $index <= 12; ++$index) {
            $this->client->jsonRequest(
                'POST',
                sprintf('/workspace/%d/chat/%d', $space->getId(), $main->getId()),
                ['body' => sprintf('Message %d', $index)],
            );
        }

        $this->client->request('GET', sprintf('/workspace/%d/chat/%d/messages', $space->getId(), $main->getId()));
        $window = $this->payload()['chatMessages'];

        self::assertCount(12, $window, 'A dozen messages fit in the opening window, which is what makes the next assertion about the cursor and not about the window.');

        // Le repère est un message, pas un numéro de page : ce qui précède le
        // sixième, ce sont les cinq premiers, et rien d'autre.
        $this->client->request(
            'GET',
            sprintf('/workspace/%d/chat/%d/older/%d', $space->getId(), $main->getId(), $window[5]['id']),
        );

        $page = $this->payload();
        $bodies = array_column($page['chatOlderMessages'], 'body');

        self::assertSame(['Message 1', 'Message 2', 'Message 3', 'Message 4', 'Message 5'], $bodies);
        self::assertFalse($page['chatHasMore'], 'Nothing precedes the first message.');
    }

    /**
     * The membership row of somebody in a room, as the payload prints it.
     *
     * @param array<string, mixed> $room
     *
     * @return array<string, mixed>
     */
    private function memberNamed(array $room, string $label): array
    {
        foreach ($room['members'] as $member) {
            if ($label === $member['label']) {
                return $member;
            }
        }

        self::fail(sprintf('No member named "%s" in this room.', $label));
    }

    /** Somebody on the space's team, which is who a conversation can be opened with. */
    private function givenTeammate(CustomerSpace $space): User
    {
        $user = $this->givenAccount('camille@aurora.test', 'Camille Martin');

        $member = new CustomerSpaceMember();
        $member
            ->setSpace($this->entityManager->getReference(CustomerSpace::class, $space->getId()))
            ->setUser($user)
            ->setRole(CustomerSpaceMemberRoleEnum::Member);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Un compte de test, dont l'adresse est unique.
     *
     * Deux méthodes de ce fichier demandent « Camille Martin » : le nom est ce
     * que les assertions lisent, l'adresse est ce que la base contraint, et
     * réutiliser la seconde faisait échouer la deuxième méthode sur une
     * violation d'unicité plutôt que sur son sujet.
     */
    private function givenAccount(string $email, string $name): User
    {
        $user = new User();
        $user->setEmail(bin2hex(random_bytes(4)).'-'.$email);
        $user->setName($name);
        $user->setType(UserTypeEnum::Backend);
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param list<array<string, mixed>> $rooms
     *
     * @return array<string, mixed>
     */
    private function named(array $rooms, string $name): array
    {
        foreach ($rooms as $room) {
            if ($room['name'] === $name) {
                return $room;
            }
        }

        self::fail(sprintf('No room called "%s" in the payload.', $name));
    }

    private function mainChannel(CustomerSpace $space): SpaceChatChannelInterface
    {
        $channel = static::getContainer()->get(SpaceChatChannelRepository::class)->findMain(
            $this->entityManager->getReference(CustomerSpace::class, $space->getId()),
        );

        self::assertInstanceOf(SpaceChatChannelInterface::class, $channel);

        return $channel;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array{0: CustomerSpace, 1: string} */
    private function givenLinkedSpace(): array
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canComment' => true,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return [$space, $this->payload()['url']];
    }

    private function givenSpace(
        string $customerName = 'Client des canaux',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('canaux@example.test');

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
