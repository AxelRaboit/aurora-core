<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * A space you are not a member of does not exist.
 *
 * **The check sits on the argument, not in the controllers**, and that is
 * what these tests hold: some ten screens receive a space, and the eleventh
 * one to be written will not think of checking. What is checked here is
 * therefore less "the list filters" than "the direct address does not get
 * through", on several routes that have nothing in common.
 *
 * And a 404, never a 403: an explicit refusal would tell someone probing
 * that the space exists and belongs to another client.
 */
final class SpaceVisibilityTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(
            sprintf("DELETE FROM %s u WHERE u.email LIKE 'portee-%%'", User::class)
        )->execute();

        parent::tearDown();
    }

    public function testATeammateOnlySeesTheSpacesTheyAreOnAndTheOthersAreNotFound(): void
    {
        $mine = $this->givenSpace('Mon espace', 'portee-a@example.test', '73282932000074');
        $theirs = $this->givenSpace('Celui des autres', 'portee-b@example.test', '39860733100024');

        $teammate = $this->givenTeammate('portee-equipier@example.test');
        $this->givenMembership($mine, $teammate, CustomerSpaceMemberRoleEnum::Member);

        $this->client->loginUser($teammate, 'admin');

        $this->client->request('GET', '/suite/studio/spaces');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', sprintf('/workspace/%d', $mine->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'son propre espace');

        // The heart of the matter: the direct address of a space that is none
        // of their business. Not a 403, which would confirm the row exists.
        $this->client->request('GET', sprintf('/workspace/%d', $theirs->getId()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), "l'espace d'un autre");
    }

    /**
     * The check applies to every route of a space, not just the first. That
     * is what a check sitting on the argument buys.
     */
    public function testEveryRouteOfAnUnseenSpaceIsNotFound(): void
    {
        $theirs = $this->givenSpace('Celui des autres', 'portee-c@example.test', '73282932000074');
        $mine = $this->givenSpace('Le mien', 'portee-d@example.test', '39860733100024');

        $teammate = $this->givenTeammate('portee-equipier2@example.test');
        $this->givenMembership($mine, $teammate, CustomerSpaceMemberRoleEnum::Member);

        $this->client->loginUser($teammate, 'admin');

        foreach (['', '/drive', '/access', '/notes'] as $suffix) {
            $this->client->request('GET', sprintf('/workspace/%d%s', $theirs->getId(), $suffix));

            self::assertSame(
                404,
                $this->client->getResponse()->getStatusCode(),
                sprintf('la route "%s" laisse passer', '' === $suffix ? '(racine)' : $suffix),
            );
        }
    }

    /**
     * The access page lists who the space is open to, addresses included: it
     * requires the right to grant that access, not just to see the space. And
     * the tab is not shown to whoever cannot open it.
     */
    public function testTheAccessPageNeedsTheRightToGiveAccess(): void
    {
        $space = $this->givenSpace('Sans droit de partage', 'portee-f@example.test', '39860733100024');

        $teammate = $this->givenTeammate('portee-equipier3@example.test');
        $teammate->setPrivileges(['studio.spaces.view', 'studio.spaces.edit']);
        $this->entityManager->flush();
        $this->givenMembership($space, $teammate, CustomerSpaceMemberRoleEnum::Member);

        $this->client->loginUser($teammate, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(sprintf('/workspace/%d/access"', $space->getId()), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/workspace/%d/access', $space->getId()));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The Drive tab is shown to whoever sees the space: reading it cannot
     * require more. Filing a file in the media library writes, and keeps the
     * right to edit.
     */
    public function testTheDriveIsReadWithTheRightToSeeAndFiledWithTheRightToEdit(): void
    {
        $space = $this->givenSpace('Drive en lecture', 'portee-g@example.test', '39860733100024');

        $teammate = $this->givenTeammate('portee-equipier4@example.test');
        $teammate->setPrivileges(['studio.spaces.view']);
        $this->entityManager->flush();
        $this->givenMembership($space, $teammate, CustomerSpaceMemberRoleEnum::Member);

        $this->client->loginUser($teammate, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d/drive', $space->getId()));
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', sprintf('/workspace/%d/drive/abc123/import', $space->getId()), server: self::FROM_THE_PAGE);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /** An administrator does not put a team together to see a space. */
    public function testAnAdminSeesEverySpaceWithoutBeingAMember(): void
    {
        $space = $this->givenSpace('Sans lui dedans', 'portee-e@example.test', '73282932000074');

        $admin = $this->givenTeammate('portee-admin@example.test', UserRoleEnum::Admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The team and its roles are the business of the space's lead. The right
     * to edit a space used to be enough: a plain member sent themselves back
     * with the lead role, and became it.
     */
    public function testAMemberCannotMakeThemselvesLeadButCanStillRenameTheSpace(): void
    {
        $space = $this->givenSpace('Mon espace', 'portee-a@example.test', '73282932000074');
        $teammate = $this->givenTeammate('portee-equipier@example.test');
        $this->givenMembership($space, $teammate, CustomerSpaceMemberRoleEnum::Member);

        $this->client->loginUser($teammate, 'admin');
        $update = fn (string $name, string $role): array => [
            'name' => $name,
            'customerId' => $space->getCustomer()->getId(),
            'timezone' => 'Europe/Paris',
            'members' => [['userId' => $teammate->getId(), 'role' => $role]],
        ];

        $this->client->jsonRequest('POST', sprintf('/suite/studio/spaces/%d/update', $space->getId()), $update('Mon espace', CustomerSpaceMemberRoleEnum::Lead->value));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('members', $this->payload()['errors'] ?? []);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/spaces/%d/update', $space->getId()), $update('Renommé', CustomerSpaceMemberRoleEnum::Member->value));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the same team, a new name');

        $this->entityManager->clear();
        $stored = $this->entityManager->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $stored);
        self::assertSame('Renommé', $stored->getName());
        self::assertSame(CustomerSpaceMemberRoleEnum::Member, $stored->getMembers()->first()->getRole());
    }

    /**
     * Whoever creates a space leads it, unless they see every space: the
     * creation accepted any team, so a space could be opened and handed to
     * others without its creator in it.
     */
    public function testWhoeverCreatesASpaceLeadsIt(): void
    {
        $creator = $this->givenTeammate('portee-createur@example.test');
        $creator->setPrivileges(['studio.spaces.view', 'studio.spaces.edit', 'studio.spaces.create']);
        $other = $this->givenTeammate('portee-autre@example.test');
        $this->entityManager->flush();

        $customer = new Customer();
        $customer->setLegalName('Client créé')->setSiret('73282932000074')->setContractualEmail('portee-client@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->loginUser($creator, 'admin');
        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Donné à un autre',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
            'members' => [['userId' => $other->getId(), 'role' => CustomerSpaceMemberRoleEnum::Lead->value]],
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $space = $this->entityManager->find(CustomerSpace::class, $this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        $roles = [];
        foreach ($space->getMembers() as $member) {
            $roles[$member->getUser()->getUserIdentifier()] = $member->getRole();
        }

        self::assertSame(CustomerSpaceMemberRoleEnum::Lead, $roles['portee-createur@example.test'] ?? null, 'the creator leads it');
        self::assertSame(CustomerSpaceMemberRoleEnum::Lead, $roles['portee-autre@example.test'] ?? null, 'the team sent is kept');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function givenTeammate(string $email, UserRoleEnum $role = UserRoleEnum::User): User
    {
        $user = new User();
        $user
            ->setEmail($email)
            ->setName('Équipier')
            ->setType(UserTypeEnum::Suite)
            ->setRoles([$role->value])
            ->setPassword('x');

        if (UserRoleEnum::User === $role) {
            $user->setPrivileges(['studio.spaces.view', 'studio.spaces.edit', 'studio.spaces.share']);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function givenSpace(string $name, string $email, string $siret): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client '.$name)
            ->setSiret($siret)
            ->setContractualEmail($email);

        $this->entityManager->persist($customer);

        $space = new CustomerSpace();
        $space
            ->setName($name)
            ->setCustomer($customer)
            ->setTimezone('Europe/Paris');

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }

    private function givenMembership(CustomerSpace $space, User $user, CustomerSpaceMemberRoleEnum $role): void
    {
        $member = new CustomerSpaceMember();
        $member->setSpace($space)->setUser($user)->setRole($role);

        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }
}
