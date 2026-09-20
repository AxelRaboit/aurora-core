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
 * Un espace dont on n'est pas membre n'existe pas.
 *
 * **Le contrôle est posé sur l'argument, pas dans les contrôleurs**, et c'est
 * ce que ces tests tiennent : une dizaine d'écrans reçoivent un espace, et le
 * onzième qu'on écrira ne pensera pas à vérifier. Ce qui est vérifié ici est
 * donc moins « la liste filtre » que « l'adresse directe ne passe pas », sur
 * plusieurs routes qui n'ont rien en commun.
 *
 * Et un 404, jamais un 403 : un refus explicite dirait à quelqu'un qui tâtonne
 * que l'espace existe et appartient à un autre client.
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

        $this->client->request('GET', '/backend/studio/spaces');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', sprintf('/workspace/%d', $mine->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'son propre espace');

        // Le cœur du sujet : l'adresse directe d'un espace qui ne le regarde
        // pas. Pas un 403, qui confirmerait que la ligne existe.
        $this->client->request('GET', sprintf('/workspace/%d', $theirs->getId()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), "l'espace d'un autre");
    }

    /**
     * Le contrôle vaut pour toutes les routes d'un espace, pas seulement la
     * première. C'est ce qu'un contrôle posé sur l'argument achète.
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

    /** Un administrateur ne compose pas d'équipe pour voir un espace. */
    public function testAnAdminSeesEverySpaceWithoutBeingAMember(): void
    {
        $space = $this->givenSpace('Sans lui dedans', 'portee-e@example.test', '73282932000074');

        $admin = $this->givenTeammate('portee-admin@example.test', UserRoleEnum::Admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
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
            ->setType(UserTypeEnum::Backend)
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
