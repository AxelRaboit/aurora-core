<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Customer;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;

/**
 * The customer endpoint, end to end.
 *
 * Two of these are about refusals rather than saves, and that is deliberate:
 * this is the row a contract is built from, so what the endpoint declines to
 * store matters as much as what it stores.
 */
final class CustomersControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private CustomerRepository $customers;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');

        $this->customers = $container->get(CustomerRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    /**
     * Cleared by query rather than by removing objects.
     *
     * The rows these tests create are made through HTTP requests, so nothing
     * here holds a managed instance of them - `remove()` on one refetched
     * afterwards fails as detached. A DQL delete needs no object identity.
     */
    protected function tearDown(): void
    {
        // Contracts first: they point at the customers, and the foreign key
        // that makes this test worth writing would refuse the other order.
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s', Contract::class),
        )->execute();
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s', Customer::class),
        )->execute();

        parent::tearDown();
    }

    public function testACustomerIsCreatedWithItsIdentityBlock(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/customers/create', [
            'legalName' => 'Boulangerie Durand',
            'legalForm' => 'SARL',
            'shareCapital' => '10 000,50',
            'siret' => '732 829 320 00074',
            'contractualEmail' => 'Contact@Durand.FR',
            'representativeFirstName' => 'Camille',
            'representativeLastName' => 'Durand',
            'representativeRole' => 'Gérante',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);

        $customer = $payload['customer'];
        self::assertSame('Boulangerie Durand', $customer['legalName']);
        // Typed with a French comma and grouped with a space, stored as cents.
        self::assertSame(1000050, $customer['shareCapitalCents']);
        self::assertSame('EUR', $customer['shareCapitalCurrency']);
        // Typed in printed groups, stored as the fourteen digits.
        self::assertSame('73282932000074', $customer['siret']);
        self::assertSame('contact@durand.fr', $customer['contractualEmail']);
        self::assertSame('Camille Durand', $customer['representativeFullName']);
        self::assertNull($customer['userId']);
    }

    public function testTheSameSiretCannotBeRecordedTwice(): void
    {
        $body = [
            'legalName' => 'Première société',
            'siret' => '73282932000074',
            'contractualEmail' => 'first@example.test',
        ];

        $this->client->jsonRequest('POST', '/backend/studio/customers/create', $body);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', '/backend/studio/customers/create', [
            ...$body,
            'legalName' => 'Seconde société',
            'contractualEmail' => 'second@example.test',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        // Pinned to the field, and naming the company that holds it - the one
        // detail that makes the collision actionable.
        self::assertArrayHasKey('siret', $payload['errors']);
        self::assertStringContainsString('Première société', $payload['errors']['siret']);
    }

    public function testAnInvalidChecksumIsRefused(): void
    {
        // The reference number with two digits transposed.
        $this->client->jsonRequest('POST', '/backend/studio/customers/create', [
            'legalName' => 'Société fautive',
            'siret' => '73282932000047',
            'contractualEmail' => 'typo@example.test',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('siret', $payload['errors']);
        self::assertSame([], $this->customers->findAll());
    }

    public function testACustomerKeepsItsOwnSiretWhenEdited(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/customers/create', [
            'legalName' => 'Atelier Martin',
            'siret' => '73282932000074',
            'contractualEmail' => 'atelier@example.test',
        ]);

        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['customer']['id'];

        // Re-sending the row's own number must not read as a duplicate of
        // itself, which is the mistake a naive uniqueness check makes.
        $this->client->jsonRequest('POST', sprintf('/backend/studio/customers/%d/update', $id), [
            'legalName' => 'Atelier Martin & Fils',
            'siret' => '73282932000074',
            'contractualEmail' => 'atelier@example.test',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Atelier Martin & Fils', $payload['customer']['legalName']);
    }

    public function testAMissingLegalNameIsRefused(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/customers/create', [
            'contractualEmail' => 'nameless@example.test',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('legalName', $payload['errors']);
    }

    /**
     * A customer a contract names is not deleted.
     *
     * The rule was already in the database - the foreign key is `RESTRICT` -
     * and that is exactly the problem this pins: the refusal used to arrive as
     * an SQL error, which reached the screen as a 500 and told the reader
     * nothing. Here it is a 422 with a sentence, pinned to the field.
     */
    public function testACustomerNamedByAContractCannotBeDeleted(): void
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Boulangerie Durand')
            ->setSiret('73282932000074')
            ->setContractualEmail('contact@durand.test')
            ->setRepresentativeFirstName('Camille')
            ->setRepresentativeLastName('Durand');
        $this->entityManager->persist($customer);

        $contract = new Contract();
        $contract->setCustomer($customer);
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/customers/%d/delete', $customer->getId()));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('customer', $payload['errors']);
        self::assertNotNull($this->customers->find($customer->getId()), 'the row is still there');
    }

    public function testACustomerWithNoContractIsDeleted(): void
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Sans contrat')
            ->setSiret('90451233600028')
            ->setContractualEmail('sans@contrat.test')
            ->setRepresentativeFirstName('Alex')
            ->setRepresentativeLastName('Autre');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
        $id = $customer->getId();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/customers/%d/delete', $id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->customers->find($id));
    }
}
