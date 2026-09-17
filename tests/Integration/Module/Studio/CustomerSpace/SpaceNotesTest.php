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
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
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
 * Les notes d'un espace, de bout en bout.
 *
 * Pesées sur les deux choses qui ne se voient pas à l'écran : qu'aucune note ne
 * fuit vers le client, et que les images d'une note soient rangées, retrouvées
 * et proposées au nettoyage plutôt que supprimées d'office.
 */
final class SpaceNotesTest extends IntegrationTestCase
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

        foreach ([SpaceNote::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testANoteIsTakenAndReadBack(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/notes/create', $space->getId()), [
            'title' => 'Brief du 17 septembre',
            'body' => [['type' => 'paragraph', 'data' => ['text' => 'Décaler la campagne.']]],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $notes = $this->payload()['notes'];
        self::assertCount(1, $notes);
        self::assertSame('Brief du 17 septembre', $notes[0]['title']);
        // Le corps entier voyage : les deux vues lisent la même note, et ouvrir
        // celle-ci ne doit rien coûter.
        self::assertSame('Décaler la campagne.', $notes[0]['body'][0]['data']['text']);
    }

    public function testANoteWithoutATitleIsRefused(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/notes/create', $space->getId()), [
            'title' => '   ',
            'body' => [],
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('title', $this->payload()['errors']);
    }

    public function testPinnedNotesComeFirst(): void
    {
        $space = $this->givenSpace();

        $this->createNote($space, 'La première');
        $this->createNote($space, 'La seconde');

        $second = $this->payload()['notes'][0];
        self::assertSame('La seconde', $second['title'], 'la plus récente est en tête');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/notes/%d/pin', $space->getId(), $this->idOf('La première')));

        // Épinglée, elle repasse devant : c'est tout ce que l'épingle fait, et
        // c'est ce que le mur doit montrer.
        self::assertSame('La première', $this->payload()['notes'][0]['title']);
        self::assertTrue($this->payload()['notes'][0]['pinned']);
    }

    /**
     * **L'image d'une note se range dans le dossier de son espace.**.
     *
     * Pas dans le tas générique des images d'édition : une note parle d'un
     * client, sa capture est à ce client. Et en brouillon, ce qui la garde hors
     * du catch-all public - une note est la seule surface qu'un client ne voit
     * pas, ses images ne doivent pas être plus publiques qu'elle.
     */
    public function testAnImageDroppedInANoteIsFiledWithTheSpace(): void
    {
        $space = $this->givenSpace();

        $this->uploadImage($space);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $document = $this->entityManager->getRepository(Document::class)
            ->find($this->payload()['document']['id']);

        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatusEnum::Draft, $document->getStatus());

        $folder = $document->getFolder();
        self::assertNotNull($folder, "l'image est rangée dans un dossier");
        self::assertSame($space->getName(), $folder->getName());

        // L'adresse rendue est celle réservée au personnel, que
        // `DocumentUrlGenerator` renvoie pour un brouillon.
        self::assertStringContainsString('/backend/ged/files', $this->payload()['document']['fileUrl']);
    }

    public function testOnlyImagesAreAccepted(): void
    {
        $space = $this->givenSpace();

        $path = sys_get_temp_dir().'/aurora-note-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($path, 'pas une image');
        $this->tempFiles[] = $path;

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/notes/images', $space->getId()),
            [],
            ['file' => new UploadedFile($path, 'note.txt', 'text/plain', null, true)],
        );

        // L'éditeur affiche ce qu'on lui rend dans un <img> : un PDF se
        // rangerait sans bruit pour s'afficher en image cassée. Refusé comme
        // le fait déjà l'envoi d'image de la médiathèque, avec le même code.
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /**
     * **Sans ça, supprimer une image viderait une note en silence.**.
     *
     * Le corps est du JSON : aucune clé étrangère ne relie ses images à la
     * médiathèque, donc rien ne casse à la suppression - le bloc reste et son
     * adresse ne répond plus, ce qui est pire, parce que la note a toujours
     * l'air entière.
     */
    public function testTheLibraryKnowsWhichNoteUsesAnImage(): void
    {
        $space = $this->givenSpace();

        $this->uploadImage($space);
        $documentId = $this->payload()['document']['id'];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/notes/create', $space->getId()), [
            'title' => 'Avec une capture',
            'body' => [[
                'type' => 'image',
                'data' => ['file' => ['url' => '/x', 'documentId' => $documentId]],
            ]],
        ]);

        $usages = static::getContainer()->get(DocumentUsageService::class)->findUsages($documentId);

        self::assertSame(1, $usages['total']);
        self::assertSame('studio.space_note', $usages['groups'][0]['type']);
        self::assertSame('Avec une capture', $usages['groups'][0]['items'][0]['label']);
    }

    /**
     * Supprimer une note ne supprime pas ses images : elle propose.
     *
     * Le même contrat que les fichiers d'une fiche, lu par le même composable.
     */
    public function testDeletingANoteOffersTheImagesNobodyUsesAnyMore(): void
    {
        $space = $this->givenSpace();

        $this->uploadImage($space);
        $documentId = $this->payload()['document']['id'];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/notes/create', $space->getId()), [
            'title' => 'À supprimer',
            'body' => [[
                'type' => 'image',
                'data' => ['file' => ['url' => '/x', 'documentId' => $documentId]],
            ]],
        ]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/notes/%d/delete', $space->getId(), $this->idOf('À supprimer')));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $orphaned = $this->payload()['orphanedDocuments'];
        self::assertCount(1, $orphaned);
        self::assertSame($documentId, $orphaned[0]['id']);
        self::assertArrayHasKey('trashPath', $orphaned[0]);

        // Proposée, pas jetée.
        self::assertNotNull($this->entityManager->getRepository(Document::class)->find($documentId));
    }

    /**
     * **La note d'un client n'est pas atteignable sous l'espace d'un autre.**.
     *
     * Elle arrive comme sa propre entité par l'URL, donc rien d'autre que cette
     * vérification ne sépare deux clients.
     */
    public function testANoteOfAnotherSpaceIsOutOfReach(): void
    {
        $mine = $this->givenSpace('Client A', '73282932000074');
        $theirs = $this->givenSpace('Client B', '55203534400028');

        $this->createNote($theirs, 'Chez eux');

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/notes/%d/delete', $mine->getId(), $this->idOf('Chez eux')),
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function uploadImage(CustomerSpace $space): void
    {
        $path = sys_get_temp_dir().'/aurora-note-'.bin2hex(random_bytes(4)).'.jpg';
        file_put_contents($path, $this->jpegBytes());
        $this->tempFiles[] = $path;

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/notes/images', $space->getId()),
            [],
            ['file' => new UploadedFile($path, 'capture.jpg', 'image/jpeg', null, true)],
        );
    }

    private function createNote(CustomerSpace $space, string $title): void
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/notes/create', $space->getId()), [
            'title' => $title,
            'body' => [],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function idOf(string $title): int
    {
        foreach ($this->payload()['notes'] as $note) {
            if ($title === $note['title']) {
                return $note['id'];
            }
        }

        self::fail(sprintf('aucune note intitulée « %s »', $title));
    }

    private function givenSpace(
        string $customerName = 'Client des notes',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer->setLegalName($customerName)->setSiret($siret)->setContractualEmail('notes@example.test');

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
