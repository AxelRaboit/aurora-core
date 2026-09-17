<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * The client-space endpoint, end to end.
 *
 * Weighted towards the two things a space does that a plain CRUD row does not:
 * it reconciles a set of members against what the form sent, and it stands
 * between a customer and their own deletion.
 */
final class CustomerSpacesControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private CustomerSpaceRepository $spaces;

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

        $this->spaces = $container->get(CustomerSpaceRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    /**
     * Cleared by query rather than by removing objects: the rows these tests
     * create are made through HTTP requests, so nothing here holds a managed
     * instance of them.
     */
    protected function tearDown(): void
    {
        // Spaces first: they point at the customers, and the `RESTRICT` that
        // makes one of these tests worth writing would refuse the other order.
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s', CustomerSpace::class),
        )->execute();
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s', Customer::class),
        )->execute();

        parent::tearDown();
    }

    public function testASpaceIsCreatedForACustomer(): void
    {
        $customer = $this->givenCustomer('Boulangerie Durand', '73282932000074');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Boulangerie Durand - Réseaux sociaux',
            'description' => 'Deux publications par semaine.',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->payload()['space'];
        self::assertSame('Boulangerie Durand - Réseaux sociaux', $space['name']);
        self::assertSame('Boulangerie Durand', $space['customerName']);
        self::assertSame('active', $space['status']);
        self::assertFalse($space['archived']);
        self::assertSame([], $space['members']);
        // Picked for the space rather than left at the column default, so two
        // spaces do not arrive the same colour.
        self::assertGreaterThanOrEqual(1, $space['colourSlot']);
        self::assertLessThanOrEqual(8, $space['colourSlot']);
    }

    public function testTwoSpacesCanNameTheSameCustomer(): void
    {
        $customer = $this->givenCustomer('Groupe Martin', '90451233600028');

        foreach (['Marque A', 'Marque B'] as $name) {
            $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
                'name' => $name,
                'customerId' => $customer->getId(),
            ]);

            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }

        self::assertCount(2, $this->spaces->findAll());
        // The decision this pins: a client with two brands runs two calendars,
        // so the relation is a ManyToOne and not a OneToOne.
        self::assertSame(2, $this->spaces->countForCustomer($customer));
    }

    public function testASpaceWithoutACustomerIsRefused(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace orphelin',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('customerId', $this->payload()['errors']);
        self::assertSame([], $this->spaces->findAll());
    }

    public function testAnUnknownCustomerIsRefusedUnderItsOwnField(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace fantôme',
            'customerId' => 987654,
        ]);

        // A 422 under the picker, not a 500: the id is checked by the Manager,
        // which is the only layer holding the repository.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('customerId', $this->payload()['errors']);
    }

    public function testMembersAreReconciledRatherThanRebuilt(): void
    {
        $customer = $this->givenCustomer('Atelier Renard', '39860733100024');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Atelier Renard - Contenus',
            'customerId' => $customer->getId(),
            'members' => [
                ['userId' => $this->admin->getId(), 'role' => 'member'],
            ],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $created = $this->payload()['space'];
        self::assertCount(1, $created['members']);
        self::assertSame('member', $created['members'][0]['role']);

        $spaceId = $created['id'];

        // Same account, different role. The row must move rather than be
        // dropped and re-inserted, which is what would churn its id.
        $this->client->jsonRequest('POST', sprintf('/backend/studio/spaces/%d/update', $spaceId), [
            'name' => 'Atelier Renard - Contenus',
            'customerId' => $customer->getId(),
            'members' => [
                ['userId' => $this->admin->getId(), 'role' => 'lead'],
            ],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $updated = $this->payload()['space'];
        self::assertCount(1, $updated['members']);
        self::assertSame('lead', $updated['members'][0]['role']);

        // And an empty set removes them.
        $this->client->jsonRequest('POST', sprintf('/backend/studio/spaces/%d/update', $spaceId), [
            'name' => 'Atelier Renard - Contenus',
            'customerId' => $customer->getId(),
            'members' => [],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->payload()['space']['members']);
    }

    public function testTheSameAccountSentTwiceLandsOnce(): void
    {
        $customer = $this->givenCustomer('Studio Double', '44306184100047');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace doublon',
            'customerId' => $customer->getId(),
            'members' => [
                ['userId' => $this->admin->getId(), 'role' => 'member'],
                ['userId' => $this->admin->getId(), 'role' => 'lead'],
            ],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $members = $this->payload()['space']['members'];
        // Collapsed by the factory before the unique index has to say no, and
        // the last role sent is the one that survives.
        self::assertCount(1, $members);
        self::assertSame('lead', $members[0]['role']);
    }

    public function testACustomerWithAnOpenSpaceCannotBeDeleted(): void
    {
        $customer = $this->givenCustomer('Client occupé', '52847521100014');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Travail en cours',
            'customerId' => $customer->getId(),
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', sprintf('/backend/studio/customers/%d/delete', $customer->getId()));

        // A sentence under the field, not the 500 the `RESTRICT` foreign key
        // produces on its own.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('customer', $this->payload()['errors']);
    }

    public function testAnArchivedSpaceIsStillReturnedByTheList(): void
    {
        $customer = $this->givenCustomer('Ancien client', '38012986600038');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Chantier terminé',
            'customerId' => $customer->getId(),
            'status' => 'archived',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = $this->payload();
        self::assertTrue($payload['space']['archived']);
        // Archiving is not deleting: the row stays in what the endpoint sends,
        // and the page is what folds it away.
        self::assertCount(1, $payload['spaces']);
    }

    public function testASpaceIsDeletedWithItsMembers(): void
    {
        $customer = $this->givenCustomer('Client éphémère', '41231234500019');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'À supprimer',
            'customerId' => $customer->getId(),
            'members' => [['userId' => $this->admin->getId(), 'role' => 'lead']],
        ]);

        $id = $this->payload()['space']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/spaces/%d/delete', $id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->spaces->find($id));
    }

    public function testTheIndexScreenRenders(): void
    {
        $this->client->request('GET', '/backend/studio/spaces');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * **Ouvrir un espace pour quelqu'un dont on n'a pas de fiche.**.
     *
     * C'est la raison d'etre du prospect : on travaille avec une societe bien
     * avant d'avoir son SIRET, et aller inventer une identite legale pour
     * pouvoir creer l'espace est exactement ce que personne ne fait.
     */
    public function testASpaceOpensAProspectWhenNoCustomerIsNamed(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Verrerie Lemoine - Lancement',
            'prospectName' => 'Verrerie Lemoine',
            'prospectEmail' => 'contact@verrerie-lemoine.test',
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $customer = $this->entityManager->getRepository(Customer::class)
            ->findOneBy(['legalName' => 'Verrerie Lemoine']);

        self::assertInstanceOf(Customer::class, $customer);
        self::assertTrue($customer->isProspect());
        self::assertSame('contact@verrerie-lemoine.test', $customer->getContractualEmail());

        // Rien de ce qui fait un client n'est invente au passage : c'est ce
        // qu'on saisira le jour de la conversion.
        self::assertNull($customer->getSiret());

        // Et l'espace pointe bien dessus : rien en aval n'a a composer avec un
        // espace qui n'appartient a personne.
        self::assertSame($customer->getId(), $this->payload()['space']['customerId']);
    }

    /**
     * **Un nom suffit, et c'est tout l'interet.**.
     *
     * On rencontre quelqu'un, on ouvre un espace pour structurer le travail, et
     * on n'a rien d'autre. Les liens d'acces de l'espace portent leur propre
     * destinataire, donc rien sur cet ecran ne depend de l'adresse du client.
     */
    public function testAProspectNeedsNothingButItsName(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Rien que le nom',
            'prospectName' => 'Verrerie Lemoine',
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $customer = $this->entityManager->getRepository(Customer::class)
            ->findOneBy(['legalName' => 'Verrerie Lemoine']);

        self::assertInstanceOf(Customer::class, $customer);
        self::assertTrue($customer->isProspect());
        self::assertNull($customer->getContractualEmail());
    }

    public function testASpaceStillNeedsSomebodyToBelongTo(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Pour personne',
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('customerId', $this->payload()['errors']);
    }

    /**
     * Une societe deja connue reste une societe deja connue.
     *
     * Le formulaire efface le nom de prospect quand on choisit dans la liste,
     * mais une requete fabriquee peut porter les deux : l'identifiant gagne, et
     * aucune fiche en double n'est creee.
     */
    public function testANamedCustomerWinsOverAProspectName(): void
    {
        $customer = $this->givenCustomer('Atelier Dupont', '73282932000074');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Les deux a la fois',
            'customerId' => $customer->getId(),
            'prospectName' => 'Verrerie Lemoine',
            'prospectEmail' => 'contact@verrerie-lemoine.test',
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($customer->getId(), $this->payload()['space']['customerId']);
        self::assertNull(
            $this->entityManager->getRepository(Customer::class)
                ->findOneBy(['legalName' => 'Verrerie Lemoine']),
        );
    }

    private function givenCustomer(string $legalName, string $siret): Customer
    {
        $customer = new Customer();
        $customer
            ->setLegalName($legalName)
            ->setSiret($siret)
            ->setContractualEmail('contact@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
