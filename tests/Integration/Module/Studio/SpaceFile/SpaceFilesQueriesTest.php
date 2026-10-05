<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\SpaceFile;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Aurora\Module\Studio\SpaceFile\Serializer\SpaceFileSerializer;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_map;
use function count;
use function json_decode;
use function sprintf;

/**
 * A space's files cost one query, however many there are.
 *
 * Each row shows its document's title, name, type and size. Left lazy, the
 * document was a query per file, on a page the client reloads after every
 * upload. The count is what regresses silently, so it is what is held.
 */
final class SpaceFilesQueriesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $documentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceFile::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf('DELETE FROM %s d WHERE d.id IN (:ids)', Document::class))
            ->setParameter('ids', $this->documentIds)
            ->execute();

        parent::tearDown();
    }

    public function testThreeFilesAndTheirDocumentsLoadInOneQuery(): void
    {
        $space = $this->givenSpace();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);

        foreach (['devis', 'logo', 'brief'] as $name) {
            $document = new Document();
            $document->setTitle(ucfirst($name))->setFilePath(sprintf('ged/2026/09/%s.pdf', $name))->setFileName($name.'.pdf')->setOriginalName($name.'.pdf')->setMimeType('application/pdf')->setSize(2048);
            $file = new SpaceFile();
            $file->setSpace($space)->setDocument($document)->addedByStudio($admin, 'Studio');
            $this->entityManager->persist($document);
            $this->entityManager->persist($file);
        }
        $this->entityManager->flush();
        $this->documentIds = array_map(static fn (SpaceFile $file): int => (int) $file->getDocument()->getId(), static::getContainer()->get(SpaceFileRepository::class)->findForSpace($space));

        $this->entityManager->clear();
        $space = $this->entityManager->find(CustomerSpace::class, $space->getId());
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $serializer = static::getContainer()->get(SpaceFileSerializer::class);
        $rows = array_map($serializer->serialize(...), static::getContainer()->get(SpaceFileRepository::class)->findForSpace($space));

        self::assertCount(3, $rows);
        self::assertSame(1, count($holder->getData()['default'] ?? []), 'the files and their documents load together');
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client des fichiers')->setSiret('73282932000074')->setContractualEmail('fichiers@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', ['name' => 'Fichiers', 'customerId' => $customer->getId(), 'timezone' => 'Europe/Paris']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->find(CustomerSpace::class, json_decode((string) $this->client->getResponse()->getContent(), true)['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
