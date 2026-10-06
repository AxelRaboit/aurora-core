<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

/**
 * A space's files, the ones that are on no item.
 *
 * Weighted towards what cannot be seen on screen: that a file is filed like
 * the others, that it cannot be reached from another client's space, and
 * that removing it offers the trash instead of deciding for you.
 */
final class SpaceFilesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        foreach ([SpaceFile::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    /**
     * **The file is filed like everything a space receives.**.
     *
     * In this space's folder and as a draft, so without a guessable public
     * address. That is what lets it be shared with the client through their
     * link without publishing it to the world.
     */
    public function testAFileDroppedOnASpaceIsFiledWithIt(): void
    {
        $space = $this->givenSpace();

        $this->upload($space);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $files = $this->payload()['spaceFiles'];
        self::assertCount(1, $files);
        self::assertFalse($files[0]['fromClient']);

        $document = $this->entityManager->getRepository(Document::class)->find($files[0]['documentId']);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatusEnum::Draft, $document->getStatus());

        $folder = $document->getFolder();
        self::assertNotNull($folder, 'le fichier est rangé dans un dossier');
        self::assertSame($space->getName(), $folder->getName());

        // The returned address is the space's, not the media library's: what
        // opens the space opens what is inside it.
        self::assertStringContainsString(sprintf('/workspace/%d/files/', $space->getId()), $files[0]['url']);
    }

    /**
     * The same document twice would read as a mistake, and it is one.
     */
    public function testTheSameDocumentIsRefusedTwice(): void
    {
        $space = $this->givenSpace();

        $this->upload($space);
        $documentId = $this->payload()['spaceFiles'][0]['documentId'];

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/files/attach', $space->getId()),
            ['documentId' => $documentId],
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('document', $this->payload()['errors']);
    }

    /**
     * **The upload goes through the administrator's policy.**.
     *
     * It does not limit types on the studio side - the media library accepts
     * anything a document can be - but it carries the size cap, and this route
     * did not consult it at all: the only wall was PHP's, whose refusal came
     * out as an error without a sentence.
     */
    public function testAFileRefusedByTheCeilingIsReportedAsSuch(): void
    {
        $space = $this->givenSpace();

        $path = sys_get_temp_dir().'/aurora-space-file-'.bin2hex(random_bytes(4)).'.jpg';
        file_put_contents($path, $this->jpegBytes());
        $this->tempFiles[] = $path;

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/files/upload', $space->getId()),
            [],
            // What PHP sets itself when its own limit has kicked in.
            ['file' => new UploadedFile($path, 'charte.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true)],
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'suite.ged.documents.errors.upload_too_large',
            $this->payload()['errors']['file'],
        );
    }

    /**
     * **Without this, deleting the document would empty the space silently.**.
     *
     * The row cascades: the deletion would not black out a thumbnail, it would
     * remove the file from the space without leaving anything behind.
     */
    public function testTheLibraryKnowsWhichSpaceCarriesAFile(): void
    {
        $space = $this->givenSpace();

        $this->upload($space);
        $documentId = $this->payload()['spaceFiles'][0]['documentId'];

        $usages = static::getContainer()->get(DocumentUsageService::class)->findUsages($documentId);

        self::assertSame(1, $usages['total']);
        self::assertSame('studio.space_file', $usages['groups'][0]['type']);
        // The space, not the document: the deletion screen already says which
        // file goes, what needs to be known is where it is used.
        self::assertSame($space->getName(), $usages['groups'][0]['items'][0]['label']);
    }

    /**
     * Removing the file from the space does not delete the document: it offers to.
     */
    public function testRemovingOffersTheDocumentNobodyUsesAnyMore(): void
    {
        $space = $this->givenSpace();

        $this->upload($space);
        $file = $this->payload()['spaceFiles'][0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/files/%d/remove', $space->getId(), $file['id']));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->payload()['spaceFiles']);

        $orphaned = $this->payload()['orphanedDocuments'];
        self::assertCount(1, $orphaned);
        self::assertSame($file['documentId'], $orphaned[0]['id']);
        self::assertArrayHasKey('trashPath', $orphaned[0]);

        // Offered, not thrown away.
        self::assertNotNull($this->entityManager->getRepository(Document::class)->find($file['documentId']));
    }

    /**
     * **One client's file cannot be reached under another client's space.**.
     *
     * It arrives as its own entity through the URL: nothing but this check
     * separates two clients, neither for reading it nor for removing it.
     */
    public function testAFileOfAnotherSpaceIsOutOfReach(): void
    {
        $mine = $this->givenSpace('Client A', '73282932000074');
        $theirs = $this->givenSpace('Client B', '55203534400028');

        $this->upload($theirs);
        $file = $this->payload()['spaceFiles'][0];

        $this->client->request('GET', sprintf('/workspace/%d/files/%d/file', $mine->getId(), $file['id']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'lecture');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/files/%d/remove', $mine->getId(), $file['id']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'retrait');
    }

    private function upload(CustomerSpace $space): void
    {
        $path = sys_get_temp_dir().'/aurora-space-file-'.bin2hex(random_bytes(4)).'.jpg';
        file_put_contents($path, $this->jpegBytes());
        $this->tempFiles[] = $path;

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/files/upload', $space->getId()),
            [],
            ['file' => new UploadedFile($path, 'charte.jpg', 'image/jpeg', null, true)],
        );
    }

    private function givenSpace(
        string $customerName = 'Client des fichiers',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer->setLegalName($customerName)->setSiret($siret)->setContractualEmail('files@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** The smallest thing the type detector calls a JPEG. */
    private function jpegBytes(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        );
    }
}
