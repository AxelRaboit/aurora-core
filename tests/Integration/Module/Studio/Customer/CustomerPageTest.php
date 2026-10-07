<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Customer;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function basename;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * A customer's page, `/suite/studio/customers/{id}`.
 *
 * **The only place where the record is written.** It had two forms: the
 * list's (share capital, RCS, VAT, representative) and the one in a space's
 * Informations tab (SIREN, landline, links, notes). Each only wrote its own
 * columns, and the SIREN could only be entered from a space. These tests hold
 * the new promise: one submission carries everything, the earlier rules
 * stay, and what the page lists around the customer follows the reader's
 * rights.
 */
final class CustomerPageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
    }

    protected function tearDown(): void
    {
        foreach ([Deliverable::class, Contract::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s u WHERE u.email LIKE 'fiche-%%'", User::class))->execute();

        parent::tearDown();
    }

    public function testThePageShowsTheWholeRecordWithItsBreadcrumb(): void
    {
        $customer = $this->givenCustomer();

        $props = $this->pageProps($customer);

        self::assertSame('Atelier Temoin', $props['customer']['legalName']);
        // The fields only a space's tab carried are on the page.
        self::assertSame('112817044', $props['customer']['siren']);
        self::assertSame('04 76 00 00 00', $props['customer']['landline']);
        self::assertSame([['label' => 'Site web', 'url' => 'https://atelier.example.com']], $props['customer']['links']);
        self::assertSame('Lu par le client', $props['customer']['informationNotes']);
        // And so are the contractual identity fields.
        self::assertSame(1_000_000, $props['customer']['shareCapitalCents']);
        self::assertSame('Lyon B 123 456 789', $props['customer']['tradeRegister']);
        self::assertSame(sprintf('/suite/studio/customers/%d/update', $customer->getId()), $props['updatePath']);

        // Studio > Clients > the company name, with the list as a link.
        $crawler = $this->client->getCrawler();
        self::assertStringStartsWith('Atelier Temoin - ', mb_trim($crawler->filter('title')->text()));
        $header = $crawler->filter('main header, body header')->first()->text();
        self::assertStringContainsString('Studio', $header);
        self::assertStringContainsString('Atelier Temoin', $header);
        self::assertGreaterThan(0, $crawler->filter('header a[href="/suite/studio/customers"]')->count());
    }

    public function testAnUnknownCustomerIsNotFound(): void
    {
        $this->client->request('GET', '/suite/studio/customers/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSeeingThePageAsksForTheViewPrivilege(): void
    {
        $customer = $this->givenCustomer();

        // The positive half first: the right to view is enough to read.
        $this->client->loginUser($this->account(['studio.customers.view']), 'admin');
        $this->client->request('GET', sprintf('/suite/studio/customers/%d', $customer->getId()));
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->account(['studio.spaces.view']), 'admin');
        $this->client->request('GET', sprintf('/suite/studio/customers/%d', $customer->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSavingAsksForTheEditPrivilege(): void
    {
        $customer = $this->givenCustomer();

        $this->client->loginUser($this->account(['studio.customers.view']), 'admin');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            ...$this->fullPayload(),
            'legalName' => 'Renomme sans droit',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        self::assertSame('Atelier Temoin', $this->reload($customer)->getLegalName());
    }

    /**
     * One submission, the whole record: the fields of the old space tab and
     * those of the contractual identity leave together, and arrive together.
     */
    public function testOneFormSavesEveryField(): void
    {
        $customer = $this->givenCustomer();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            'status' => 'client',
            'legalName' => 'Atelier Temoin & Fils',
            'legalForm' => 'SAS',
            'shareCapital' => '2 500,50',
            'shareCapitalCurrency' => 'EUR',
            'registeredOffice' => '2 rue Neuve, 38000 Grenoble',
            'siret' => '732 829 320 00074',
            'siren' => '732 829 320',
            'tradeRegister' => 'Grenoble B 732 829 320',
            'vatNumber' => 'FR99732829320',
            'activitySector' => 'Menuiserie',
            'representativeFirstName' => 'Camille',
            'representativeLastName' => 'Durand',
            'representativeRole' => 'Presidente',
            'contractualEmail' => 'Direction@Atelier.TEST',
            'phone' => '06 11 22 33 44',
            'landline' => '04 76 11 22 33',
            'links' => [
                ['label' => 'Site web', 'url' => 'https://atelier-fils.example.com'],
                ['label' => '', 'url' => ''],
                ['label' => 'Instagram', 'url' => 'https://instagram.example.com/atelier'],
            ],
            'informationNotes' => 'Atelier ouvert le samedi.',
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->json();
        self::assertSame('73282932000074', $payload['customer']['siret']);
        self::assertSame('732829320', $payload['customer']['siren']);

        $this->entityManager->clear();
        $stored = $this->reload($customer);

        self::assertSame('Atelier Temoin & Fils', $stored->getLegalName());
        self::assertSame(CustomerStatusEnum::Client, $stored->getStatus());
        self::assertSame('SAS', $stored->getLegalForm());
        self::assertSame(250_050, $stored->getShareCapitalCents());
        self::assertSame(CurrencyEnum::EUR, $stored->getShareCapitalCurrency());
        self::assertSame('2 rue Neuve, 38000 Grenoble', $stored->getRegisteredOffice());
        self::assertSame('73282932000074', $stored->getSiret());
        self::assertSame('732829320', $stored->getSiren());
        self::assertSame('Grenoble B 732 829 320', $stored->getTradeRegister());
        self::assertSame('FR99732829320', $stored->getVatNumber());
        self::assertSame('Menuiserie', $stored->getActivitySector());
        self::assertSame('Camille Durand', $stored->getRepresentativeFullName());
        self::assertSame('Presidente', $stored->getRepresentativeRole());
        self::assertSame('direction@atelier.test', $stored->getContractualEmail());
        self::assertSame('06 11 22 33 44', $stored->getPhone());
        self::assertSame('04 76 11 22 33', $stored->getLandline());
        // The row left empty was dropped, not refused.
        self::assertSame([
            ['label' => 'Site web', 'url' => 'https://atelier-fils.example.com'],
            ['label' => 'Instagram', 'url' => 'https://instagram.example.com/atelier'],
        ], $stored->getLinks());
        self::assertSame('Atelier ouvert le samedi.', $stored->getInformationNotes());
    }

    /** The Clients screen refused to clear a signed customer's address; so does the page. */
    public function testASignedClientKeepsTheAddressTheirContractsGoTo(): void
    {
        $customer = $this->givenCustomer(CustomerStatusEnum::Client);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            ...$this->fullPayload(),
            'status' => 'client',
            'contractualEmail' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('contractualEmail', $this->json()['errors']);

        $this->entityManager->clear();
        self::assertSame('temoin@example.test', $this->reload($customer)->getContractualEmail());
    }

    /** A SIREN that does not match the SIRET is refused, on its own field. */
    public function testTwoNumbersThatDisagreeAreRefusedOnTheSirenField(): void
    {
        $customer = $this->givenCustomer();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            ...$this->fullPayload(),
            'siret' => '11281704400004',
            'siren' => '732456306',
        ]);

        self::assertResponseStatusCodeSame(422);
        $errors = $this->json()['errors'];
        self::assertSame('suite.studio.customers.errors.siren_mismatch', $errors['siren'] ?? null);
        self::assertArrayNotHasKey('siret', $errors);
    }

    public function testASirenWithABadCheckDigitIsRefused(): void
    {
        $customer = $this->givenCustomer();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            ...$this->fullPayload(),
            'siret' => null,
            'siren' => '112817040',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('suite.studio.customers.errors.siren_invalid', $this->json()['errors']['siren'] ?? null);
    }

    public function testASiretHeldByAnotherCustomerIsRefusedAndNamed(): void
    {
        $other = new Customer();
        $other->setLegalName('Societe Voisine')->setSiret('73282932000074');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $customer = $this->givenCustomer();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            ...$this->fullPayload(),
            'siret' => '73282932000074',
            'siren' => null,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Societe Voisine', $this->json()['errors']['siret']);
    }

    /** A half-filled row is an error set on that row; so is a non-web address. */
    public function testAHalfFilledOrNonWebLinkIsRefusedOnItsRow(): void
    {
        $customer = $this->givenCustomer();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/update', $customer->getId()), [
            ...$this->fullPayload(),
            'links' => [
                ['label' => 'Site web', 'url' => 'https://atelier.example.com'],
                ['label' => 'Piege', 'url' => 'javascript:alert(1)'],
                ['label' => 'Sans adresse', 'url' => ''],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $errors = $this->json()['errors'];
        self::assertSame('suite.studio.customers.errors.link_url_invalid', $errors['links[1].url'] ?? null);
        self::assertSame('suite.studio.customers.errors.link_url_required', $errors['links[2].url'] ?? null);
    }

    /**
     * What surrounds the customer follows the reader's rights: a list they
     * cannot open does not exist for them, nor does a colleague's personal
     * deliverable, nor a space they are not a member of.
     */
    public function testRelatedListsFollowTheReadersRights(): void
    {
        $customer = $this->givenCustomer();

        $contract = new Contract();
        $contract->setCustomer($customer);
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $space = $this->givenSpace($customer, 'Refonte du site');
        $shared = $this->givenDeliverable($customer, 'Proposition commerciale', DeliverableScopeEnum::Shared);
        $personal = $this->givenDeliverable($customer, 'Notes perso de l admin', DeliverableScopeEnum::Personal);

        // Whoever sees everything sees the three lists, in full.
        $related = $this->pageProps($customer)['related'];
        self::assertSame([sprintf('/suite/studio/contracts/%d', $contract->getId())], array_column($related['contracts'], 'url'));
        self::assertSame('draft', $related['contracts'][0]['status']);
        self::assertSame(['Refonte du site'], array_column($related['spaces'], 'label'));
        self::assertEqualsCanonicalizing([$shared, $personal], array_column($related['deliverables'], 'id'));

        // A colleague who sees customers and deliverables, without contracts,
        // and who is a member of no space.
        $this->client->loginUser($this->account(['studio.customers.view', 'studio.deliverables.view', 'studio.spaces.view']), 'admin');
        $props = $this->pageProps($customer);
        $related = $props['related'];

        self::assertNull($related['contracts'], 'pas de liens vers des contrats qui répondraient 403');
        self::assertNull($props['contractsPath']);
        self::assertSame([], $related['spaces'], 'un espace dont il n est pas membre ne se liste pas');
        self::assertSame([$shared], array_column($related['deliverables'], 'id'), 'le livrable perso de l admin reste le sien');

        // Without the spaces right or the deliverables right, none of these lists.
        $this->client->loginUser($this->account(['studio.customers.view']), 'admin');
        $related = $this->pageProps($customer)['related'];
        self::assertNull($related['spaces']);
        self::assertNull($related['deliverables']);
        self::assertNull($related['contracts']);
    }

    /** Deleting from the page keeps the earlier guard: a customer named by a space stays. */
    public function testDeletionKeepsItsGuard(): void
    {
        $customer = $this->givenCustomer();
        $this->givenSpace($customer, 'Projet en cours');

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/delete', $customer->getId()));

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('customer', $this->json()['errors']);
    }

    /** Conversion is done from the page too, through its own route. */
    public function testAProspectIsConvertedFromThePage(): void
    {
        $customer = $this->givenCustomer();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/convert', $customer->getId()), []);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        self::assertSame(CustomerStatusEnum::Client, $this->reload($customer)->getStatus());
    }

    /** The list no longer edits: it leads to the page, and no longer receives accounts. */
    public function testTheListLeadsToThePage(): void
    {
        $this->givenCustomer();

        $this->client->request('GET', '/suite/studio/customers');
        self::assertResponseIsSuccessful();

        $props = json_decode((string) $this->client->getCrawler()
            ->filter('[data-symfony--ux-vue--vue-component-value="studio/suite/customers/CustomersApp"]')
            ->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('/suite/studio/customers/__id__', $props['showPath']);
        self::assertArrayNotHasKey('updatePath', $props);
        self::assertArrayNotHasKey('users', $props);
    }

    /** @return array<string, mixed> */
    private function pageProps(Customer $customer): array
    {
        $this->client->request('GET', sprintf('/suite/studio/customers/%d', $customer->getId()));
        self::assertResponseIsSuccessful();

        $node = $this->client->getCrawler()->filter('[data-symfony--ux-vue--vue-component-value="studio/suite/customers/CustomerPageApp"]');
        self::assertSame(1, $node->count());

        return json_decode((string) $node->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function fullPayload(): array
    {
        return [
            'status' => 'prospect',
            'legalName' => 'Atelier Temoin',
            'contractualEmail' => 'temoin@example.test',
            'siret' => '11281704400004',
            'siren' => '112817044',
        ];
    }

    private function reload(Customer $customer): Customer
    {
        $stored = $this->entityManager->getRepository(Customer::class)->find($customer->getId());
        self::assertInstanceOf(Customer::class, $stored);

        return $stored;
    }

    /**
     * A suite account, with exactly these privileges and no others.
     *
     * @param list<string> $privileges
     */
    private function account(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('fiche-'.bin2hex(random_bytes(4)).'@aurora.test')
            ->setName('Collegue')
            ->setType(UserTypeEnum::Suite)
            ->setPassword('x')
            ->setRoles(['ROLE_USER'])
            ->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function givenCustomer(CustomerStatusEnum $status = CustomerStatusEnum::Prospect): Customer
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Atelier Temoin')
            ->setStatus($status)
            ->setContractualEmail('temoin@example.test')
            ->setSiret('11281704400004')
            ->setSiren('112817044')
            ->setLandline('04 76 00 00 00')
            ->setLinks([['label' => 'Site web', 'url' => 'https://atelier.example.com']])
            ->setInformationNotes('Lu par le client')
            ->setShareCapitalCents(1_000_000)
            ->setShareCapitalCurrency(CurrencyEnum::EUR)
            ->setTradeRegister('Lyon B 123 456 789');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function givenSpace(Customer $customer, string $name): CustomerSpace
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => $name,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /** A Studio deliverable written by the administrator, linked to the customer. */
    private function givenDeliverable(Customer $customer, string $title, DeliverableScopeEnum $scope): int
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => $title, 'scope' => $scope->value]);
        self::assertResponseIsSuccessful();

        $id = (int) basename((string) $this->json()['editPath']);

        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $deliverable);
        $deliverable->setCustomer($this->entityManager->find(Customer::class, $customer->getId()));
        $this->entityManager->flush();

        return $id;
    }
}
