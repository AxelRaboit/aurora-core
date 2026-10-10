<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Dashboard;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\ClientNotice\Entity\ClientNotice;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceTeamNotifier;
use Aurora\Module\Studio\Dashboard\StudioStatsProvider;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatReadMarker;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function json_decode;
use function parse_url;
use function sprintf;

use const PHP_URL_PATH;

/**
 * The waits the dashboard did not show.
 *
 * « À traiter » listed follow-ups, countersignatures and the content
 * calendar; it said nothing of a client message nobody read, nor of a
 * client's access about to run out. And nobody was told they had been put
 * on a space's team.
 */
final class StudioWaitsTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private User $admin;

    private EntityManagerInterface $entityManager;

    private int $customerId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();
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
        $this->entityManager->createQuery(sprintf('DELETE FROM %s n WHERE n.type LIKE :prefix', Notification::class))->setParameter('prefix', 'studio.space.%')->execute();

        foreach ([SpaceChatReadMarker::class, ClientNotice::class, SpaceChatMessage::class, SpaceAccessLink::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.email LIKE :email', User::class))->setParameter('email', 'equipier-%')->execute();

        parent::tearDown();
    }

    public function testAnUnreadClientMessageAndAnExpiringAccessAreToDo(): void
    {
        $space = $this->givenSpaceWithMe();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space), [
            'recipientEmail' => 'camille@societe.test', 'label' => 'Camille', 'canChat' => true, 'validForDays' => 5,
        ]);
        $url = (string) parse_url($this->payload()['url'], PHP_URL_PATH);
        $linkId = $this->payload()['links'][0]['id'];

        $this->client->jsonRequest('POST', $url.'/chat/'.$this->mainChannel($space), ['body' => 'Une question.']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $stats = $this->stats();
        self::assertSame(1, $stats['unreadClientMessages']);
        self::assertStringContainsString(sprintf('/workspace/%d', $space), (string) $stats['unreadClientMessagesPath']);
        self::assertSame(1, $stats['spaceLinksExpiring']);

        // Renewed in place: no longer about to expire, same address.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/%d/extend', $space, $linkId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->stats()['spaceLinksExpiring']);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** Put on a team by somebody else, a colleague hears of it; adding oneself is not news. */
    public function testJoiningATeamIsTold(): void
    {
        $colleague = new User();
        $colleague->setEmail('equipier-'.bin2hex(random_bytes(3)).'@example.test')->setName('Équipier')
            ->setType(UserTypeEnum::Suite)->setRoles([UserRoleEnum::User->value])->setPassword('x');
        $this->entityManager->persist($colleague);
        $this->entityManager->flush();

        $space = $this->givenSpaceWithMe();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/spaces/%d/update', $space), [
            'name' => 'Espace attendu',
            'customerId' => $this->customerId,
            'members' => [
                ['userId' => $this->admin->getId(), 'role' => 'lead'],
                ['userId' => $colleague->getId(), 'role' => 'member'],
            ],
        ]);

        $told = $this->entityManager->getRepository(Notification::class)->findBy(['type' => SpaceTeamNotifier::TYPE_ADDED]);
        self::assertCount(1, $told);
        self::assertSame($colleague->getId(), $told[0]->getRecipient()->getId());
    }

    private function givenSpaceWithMe(): int
    {
        $customer = new Customer();
        $customer->setLegalName('Client attendu')->setSiret('73282932000074')->setContractualEmail('attente@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', ['name' => 'Espace attendu', 'customerId' => $customer->getId()]);
        $space = (int) $this->payload()['space']['id'];

        $member = new CustomerSpaceMember();
        $member
            ->setSpace($this->entityManager->getReference(CustomerSpace::class, $space))
            ->setUser($this->entityManager->getReference(User::class, $this->admin->getId()))
            ->setRole(CustomerSpaceMemberRoleEnum::Lead);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->customerId = (int) $customer->getId();

        return $space;
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        $this->entityManager->clear();
        $admin = $this->entityManager->find(User::class, $this->admin->getId());
        self::assertInstanceOf(User::class, $admin);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));

        return static::getContainer()->get(StudioStatsProvider::class)->getStats()['studio'];
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
