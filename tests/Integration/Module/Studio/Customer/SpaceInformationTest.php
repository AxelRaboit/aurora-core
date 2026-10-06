<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Customer;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * La fiche d'un client, vue depuis son espace.
 *
 * **En lecture.** L'onglet Informations avait son propre formulaire et sa
 * propre route d'écriture, avec d'autres champs que l'écran des clients. La
 * fiche s'écrit maintenant sur la page du client, et seulement là : la route
 * de l'onglet n'existe plus, et l'onglet mène à la page pour qui a le droit de
 * la modifier.
 *
 * Ce qui ne change pas : la fiche appartient au client (deux espaces montrent
 * la même), et la page que lit le client montre ce qu'elle montrait.
 */
final class SpaceInformationTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkManagerInterface $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(SpaceAccessLinkManagerInterface::class);

        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
    }

    protected function tearDown(): void
    {
        foreach ([SpaceResource::class, SpaceAccessLink::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    /**
     * La route d'écriture de l'onglet n'existe plus.
     *
     * **La moitié positive d'abord** : le même compte enregistre bien la fiche
     * par la page du client. Un 404 seul ne prouverait rien, il serait le même
     * si l'espace n'existait pas.
     */
    public function testTheTabNoLongerWritesTheRecord(): void
    {
        $customer = $this->givenCustomer();
        $space = $this->givenSpace($customer, 'Premier projet');

        $this->saveOnTheCustomerPage($customer, ['phone' => '06 11 22 33 44']);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/information/save', $space->getId()), [
            'legalName' => 'Renomme depuis un espace',
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Customer::class)->find($customer->getId());
        self::assertSame('Atelier Temoin', $stored?->getLegalName());
        self::assertSame('06 11 22 33 44', $stored?->getPhone());
    }

    /** L'onglet reçoit la fiche à lire, et plus d'adresse où l'enregistrer. */
    public function testTheTabReceivesTheRecordToReadAndNoSavePath(): void
    {
        $customer = $this->givenCustomer();
        $space = $this->givenSpace($customer, 'Premier projet');

        $props = $this->spaceProps($space);

        self::assertSame('Atelier Temoin', $props['information']['legalName']);
        self::assertArrayNotHasKey('informationSavePath', $props);
        self::assertSame(sprintf('/suite/studio/customers/%d', $customer->getId()), $props['customerPath']);
    }

    /**
     * « Modifier la fiche » ne mène à la page que pour qui peut l'y modifier.
     *
     * Un lien vers un formulaire en lecture seule serait une promesse que la
     * page ne tient pas ; quelqu'un qui tient le tableau d'un espace sans
     * toucher aux fiches clients n'a pas de lien du tout.
     */
    public function testTheLinkToTheCustomerPageFollowsTheEditPrivilege(): void
    {
        $customer = $this->givenCustomer();
        $space = $this->givenSpace($customer, 'Premier projet');

        $reader = $this->account(['studio.spaces.view', 'studio.customers.view']);
        $this->joinSpace($space, $reader);
        $this->client->loginUser($reader, 'admin');
        self::assertNull($this->spaceProps($space)['customerPath']);

        $production = $this->account(['studio.spaces.view', 'studio.spaces.edit']);
        $this->joinSpace($space, $production);
        $this->client->loginUser($production, 'admin');
        self::assertNull($this->spaceProps($space)['customerPath']);

        $editor = $this->account(['studio.spaces.view', 'studio.customers.view', 'studio.customers.edit']);
        $this->joinSpace($space, $editor);
        $this->client->loginUser($editor, 'admin');
        self::assertSame(sprintf('/suite/studio/customers/%d', $customer->getId()), $this->spaceProps($space)['customerPath']);
    }

    /** La fiche est au client : modifiée sur sa page, ses deux espaces montrent la même. */
    public function testTwoSpacesOfTheSameCustomerShowTheSameRecord(): void
    {
        $customer = $this->givenCustomer();
        $first = $this->givenSpace($customer, 'Premier projet');
        $second = $this->givenSpace($customer, 'Second projet');

        $this->saveOnTheCustomerPage($customer, ['phone' => '06 55 44 33 22', 'siren' => '112817044']);

        self::assertSame('06 55 44 33 22', $this->spaceProps($first)['information']['phone']);
        self::assertSame('112817044', $this->spaceProps($second)['information']['siren']);
    }

    /** Autour du client, depuis un espace : les autres espaces, pas celui où l'on est. */
    public function testTheRelatedSpacesLeaveOutTheCurrentOne(): void
    {
        $customer = $this->givenCustomer();
        $first = $this->givenSpace($customer, 'Premier projet');
        $this->givenSpace($customer, 'Second projet');

        self::assertSame(['Second projet'], array_column($this->spaceProps($first)['related']['spaces'], 'label'));
    }

    /**
     * Ce que lit le client ne change pas : l'onglet de sa page n'existe pas
     * tant que la fiche ne dit rien, et montre ce qu'elle dit ensuite.
     */
    public function testTheClientTabAppearsOnlyOnceTheSheetSaysSomething(): void
    {
        $customer = new Customer();
        $customer->setLegalName('Societe Sans Fiche')->setContractualEmail(null);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $space = $this->givenSpace($customer, 'Projet nu');
        $link = $this->givenLink($space);

        // Les propriétés voyagent dans un attribut, donc les guillemets y sont
        // échappés : c'est bien ce HTML-là que le client reçoit.
        self::assertStringContainsString('information&quot;:null', $this->clientPage($link));

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            'legalName' => 'Societe Sans Fiche',
            'phone' => '06 00 11 22 33',
            'links' => [['label' => 'Site web', 'url' => 'https://societe.example.com']],
            'informationNotes' => 'Ouvert le samedi',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $page = $this->clientPage($link);
        self::assertStringContainsString('06 00 11 22 33', $page);
        self::assertStringContainsString('https:\\/\\/societe.example.com', $page);
        self::assertStringContainsString('Ouvert le samedi', $page);
        // Rien de contractuel ne voyage vers le client.
        self::assertStringNotContainsString('shareCapitalCents', $page);
    }

    /**
     * Enregistrer la fiche, par le seul chemin qui l'écrit : la page du client.
     *
     * @param array<string, mixed> $payload
     */
    private function saveOnTheCustomerPage(Customer $customer, array $payload): void
    {
        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            'legalName' => 'Atelier Temoin',
            'contractualEmail' => 'temoin@example.test',
            ...$payload,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function spaceProps(CustomerSpace $space): array
    {
        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $node = $this->client->getCrawler()->filter('[data-symfony--ux-vue--vue-component-value="studio/suite/content/SpaceContentApp"]');
        self::assertSame(1, $node->count());

        return json_decode((string) $node->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function clientPage(SpaceAccessLinkInterface $link): string
    {
        $this->client->request('GET', sprintf('/spaces/%s/%s', $link->getSelector(), (string) $link->getPlainToken()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }

    private function givenLink(CustomerSpace $space): SpaceAccessLinkInterface
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return $this->links->issue($fresh, 'client@example.test', 'Le client', 30, true, true);
    }

    private function joinSpace(CustomerSpace $space, User $user): void
    {
        // Relus : une requête passée entre-temps a pu vider l'unité de travail.
        $space = $this->entityManager->find(CustomerSpace::class, $space->getId());
        $user = $this->entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(CustomerSpace::class, $space);
        self::assertInstanceOf(User::class, $user);

        $member = new CustomerSpaceMember();
        $member->setSpace($space)->setUser($user)->setRole(CustomerSpaceMemberRoleEnum::Member);

        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    /**
     * Un compte de production, avec ces privilèges-là et pas d'autres.
     *
     * @param list<string> $privileges
     */
    private function account(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('espace-'.bin2hex(random_bytes(4)).'@aurora.test')
            ->setName('Production')
            ->setType(UserTypeEnum::Suite)
            ->setPassword('x')
            ->setRoles(['ROLE_USER'])
            ->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /** Une société dont l'identité contractuelle est complète, comme après un contrat. */
    private function givenCustomer(): Customer
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Atelier Temoin')
            ->setContractualEmail('temoin@example.test')
            ->setShareCapitalCents(1_000_000)
            ->setShareCapitalCurrency(CurrencyEnum::EUR)
            ->setTradeRegister('Lyon B 123 456 789')
            ->setVatNumber('FR12345678901')
            ->setRepresentativeFirstName('Marie')
            ->setRepresentativeLastName('Dupont')
            ->setRepresentativeRole('Gerante');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function givenSpace(Customer $customer, string $name): CustomerSpace
    {
        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => $name,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
