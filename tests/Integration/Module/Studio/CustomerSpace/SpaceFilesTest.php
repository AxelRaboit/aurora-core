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
 * Les fichiers d'un espace, ceux qui ne sont sur aucune fiche.
 *
 * Pesée sur ce qui ne se voit pas à l'écran : qu'un fichier se range comme les
 * autres, qu'on ne l'atteigne pas depuis l'espace d'un autre client, et que le
 * retirer propose la corbeille au lieu d'en décider.
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
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
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
     * **Le fichier se range comme tout ce qu'un espace reçoit.**.
     *
     * Dans le dossier de cet espace et en brouillon, donc sans adresse publique
     * devinable. C'est ce qui permet de le partager avec le client par son lien
     * sans le publier au monde.
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

        // L'adresse rendue est celle de l'espace, pas celle de la médiathèque :
        // ce qui ouvre l'espace ouvre ce qu'il y a dedans.
        self::assertStringContainsString(sprintf('/workspace/%d/files/', $space->getId()), $files[0]['url']);
    }

    /**
     * Le même document deux fois se lirait comme une erreur, et c'en est une.
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
     * **Le dépôt passe par la politique de l'administrateur.**.
     *
     * Elle ne limite pas les types côté studio - la médiathèque accepte tout ce
     * qu'un document peut être - mais elle porte le plafond de taille, et cette
     * route ne la consultait pas du tout : le seul mur était celui de PHP, dont
     * le refus ressortait en erreur sans phrase.
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
            // Ce que PHP pose lui-même quand sa propre limite a mordu.
            ['file' => new UploadedFile($path, 'charte.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true)],
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'backend.ged.documents.errors.upload_too_large',
            $this->payload()['errors']['file'],
        );
    }

    /**
     * **Sans ça, supprimer le document viderait l'espace en silence.**.
     *
     * La ligne cascade : la suppression ne noircirait pas une vignette, elle
     * retirerait le fichier de l'espace sans rien laisser derrière.
     */
    public function testTheLibraryKnowsWhichSpaceCarriesAFile(): void
    {
        $space = $this->givenSpace();

        $this->upload($space);
        $documentId = $this->payload()['spaceFiles'][0]['documentId'];

        $usages = static::getContainer()->get(DocumentUsageService::class)->findUsages($documentId);

        self::assertSame(1, $usages['total']);
        self::assertSame('studio.space_file', $usages['groups'][0]['type']);
        // L'espace, pas le document : l'écran de suppression dit déjà quel
        // fichier part, ce qu'il faut savoir c'est chez qui il sert.
        self::assertSame($space->getName(), $usages['groups'][0]['items'][0]['label']);
    }

    /**
     * Retirer le fichier de l'espace ne supprime pas le document : ça propose.
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

        // Proposé, pas jeté.
        self::assertNotNull($this->entityManager->getRepository(Document::class)->find($file['documentId']));
    }

    /**
     * **Le fichier d'un client n'est pas atteignable sous l'espace d'un autre.**.
     *
     * Il arrive comme sa propre entité par l'URL : rien d'autre que cette
     * vérification ne sépare deux clients, ni pour le lire ni pour le retirer.
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

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
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

    /** La plus petite chose que le détecteur de type appelle un JPEG. */
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
