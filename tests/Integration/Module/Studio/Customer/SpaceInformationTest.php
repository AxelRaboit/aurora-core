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
 * A customer's record, seen from their space.
 *
 * **Read-only.** The Informations tab had its own form and its own write
 * route, with other fields than the customers screen. The record is now
 * written on the customer's page, and only there: the tab's route no longer
 * exists, and the tab leads to the page for whoever has the right to edit it.
 *
 * What does not change: the record belongs to the customer (two spaces show
 * the same one), and the page the client reads shows what it showed.
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
     * The tab's write route no longer exists.
     *
     * **The positive half first**: the same account does save the record
     * through the customer's page. A 404 alone would prove nothing, it would
     * be the same if the space did not exist.
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

    /** The tab receives the record to read, and no longer an address to save it to. */
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
     * "Modifier la fiche" only leads to the page for whoever can edit it there.
     *
     * A link to a read-only form would be a promise the page does not keep;
     * someone who runs a space's board without touching customer records gets
     * no link at all.
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

    /** The record belongs to the customer: edited on their page, both their spaces show the same. */
    public function testTwoSpacesOfTheSameCustomerShowTheSameRecord(): void
    {
        $customer = $this->givenCustomer();
        $first = $this->givenSpace($customer, 'Premier projet');
        $second = $this->givenSpace($customer, 'Second projet');

        $this->saveOnTheCustomerPage($customer, ['phone' => '06 55 44 33 22', 'siren' => '112817044']);

        self::assertSame('06 55 44 33 22', $this->spaceProps($first)['information']['phone']);
        self::assertSame('112817044', $this->spaceProps($second)['information']['siren']);
    }

    /** Around the customer, from a space: the other spaces, not the one you are in. */
    public function testTheRelatedSpacesLeaveOutTheCurrentOne(): void
    {
        $customer = $this->givenCustomer();
        $first = $this->givenSpace($customer, 'Premier projet');
        $this->givenSpace($customer, 'Second projet');

        self::assertSame(['Second projet'], array_column($this->spaceProps($first)['related']['spaces'], 'label'));
    }

    /**
     * What the client reads does not change: the tab on their page does not
     * exist as long as the record says nothing, and shows what it says after.
     */
    public function testTheClientTabAppearsOnlyOnceTheSheetSaysSomething(): void
    {
        $customer = new Customer();
        $customer->setLegalName('Societe Sans Fiche')->setContractualEmail(null);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $space = $this->givenSpace($customer, 'Projet nu');
        $link = $this->givenLink($space);

        // The props travel in an attribute, so the quotes in it are escaped:
        // that is indeed the HTML the client receives.
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
        // Nothing contractual travels to the client.
        self::assertStringNotContainsString('shareCapitalCents', $page);
    }

    /**
     * Saves the record, through the only path that writes it: the customer's page.
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
        // Read again: a request made in between may have cleared the unit of work.
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
     * A production account, with exactly these privileges and no others.
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

    /** A company whose contractual identity is complete, as after a contract. */
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
