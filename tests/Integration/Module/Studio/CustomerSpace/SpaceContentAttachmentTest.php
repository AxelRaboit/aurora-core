<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_column;
use function array_merge;
use function array_unique;
use function array_values;
use function base64_decode;
use function bin2hex;
use function file_put_contents;
use function is_file;
use function json_decode;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * The files on a piece of content, end to end.
 *
 * Weighted towards the four things the join row does that a column would not:
 * it carries who put the file there in a form that survives them, it refuses
 * the same document twice, it refuses to reach across spaces, and detaching it
 * leaves the document alone.
 */
final class SpaceContentAttachmentTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columns;

    private SpaceContentAttachmentRepository $attachments;

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
        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->attachments = $container->get(SpaceContentAttachmentRepository::class);
    }

    protected function tearDown(): void
    {
        // Attachments, then cards, then steps, then spaces, then customers:
        // each points at the one after it.
        foreach ([
            SpaceContentAttachment::class,
            SpaceContentItem::class,
            SpaceContentColumn::class,
            CustomerSpace::class,
            Customer::class,
        ] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testADocumentIsPutOnACardAndSignedByWhoeverDidIt(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Un contenu à illustrer');
        $document = $this->givenDocument('Une photo');

        $this->attach($space, $item['id'], (int) $document->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = $this->payload();

        // Keyed by card id, like the threads: the whole space arrives in one
        // query because every view draws every card.
        self::assertArrayHasKey('attachments', $payload);
        $files = $payload['attachments'][$item['id']];
        self::assertCount(1, $files);

        self::assertSame((int) $document->getId(), $files[0]['documentId']);
        self::assertSame('Une photo', $files[0]['title']);
        self::assertFalse($files[0]['fromClient']);
        // The durable label, not the relation - it has to survive the account.
        self::assertNotSame('', $files[0]['author']);
    }

    /**
     * A file is a reference, and the payload never carries a stored address.
     *
     * The address of a document changes when the file behind it is replaced, so
     * it is resolved at render time. This checks the shape the front relies on
     * rather than the value: `preview` is null for anything that is not an
     * image, because pointing an `<img>` at a PDF is how a file that uploaded
     * correctly ends up looking like a failure.
     */
    public function testANonImageCarriesNoPreview(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Un contenu avec un PDF');
        $document = $this->givenDocument('Un dossier de presse', 'application/pdf');

        $this->attach($space, $item['id'], (int) $document->getId());

        $files = $this->payload()['attachments'][$item['id']];

        self::assertNull($files[0]['preview']);
        self::assertSame('application/pdf', $files[0]['mimeType']);
    }

    public function testTheSameDocumentIsRefusedTwiceOnOneCard(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Un contenu');
        $document = $this->givenDocument('Une photo');

        $this->attach($space, $item['id'], (int) $document->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->attach($space, $item['id'], (int) $document->getId());

        // Refused with a sentence rather than a unique-index violation: two
        // thumbnails of one picture read as a mistake to whoever sees the card.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('document', $this->payload()['errors']);
    }

    /**
     * One space cannot reach into another's card.
     *
     * Every route names the space and the card arrives as its own entity, so
     * nothing but this check stops a crafted request from hanging a file off
     * another client's content.
     */
    public function testACardOfAnotherSpaceIsRefused(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace('Autre client', '39860733300060');

        $item = $this->givenItem($theirs, 'Le contenu du voisin');
        $document = $this->givenDocument('Une photo');

        $this->attach($mine, $item['id'], (int) $document->getId());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->attachments->count([]));
    }

    /**
     * Taking a file off a card leaves the file in GED.
     *
     * The two are different enough that conflating them would be a trap: this
     * says something about the card, not about the asset, and a screen with no
     * warning on it must not destroy a document.
     */
    public function testDetachingKeepsTheDocument(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Un contenu');
        $document = $this->givenDocument('Une photo');

        $this->attach($space, $item['id'], (int) $document->getId());
        $attachmentId = $this->payload()['attachments'][$item['id']][0]['id'];

        $this->client->request('POST', sprintf('/workspace/%d/attachments/%d/detach', $space->getId(), $attachmentId));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->attachments->count([]));

        $this->entityManager->clear();
        self::assertInstanceOf(
            Document::class,
            $this->entityManager->getRepository(Document::class)->find($document->getId()),
        );
    }

    /**
     * The library says who is holding a file before it is deleted.
     *
     * This relation is the one that punishes silence hardest. It is declared
     * `onDelete: CASCADE`, so deleting the document does not blank a preview -
     * it takes the attachment row with it, and the file leaves the client's
     * space with nothing left to show it was ever there. Until this provider
     * existed the deletion screen reported no usage at all for exactly that
     * document.
     *
     * Asked through the aggregator rather than the provider, because the thing
     * that can break is the container tag: a provider written and not tagged
     * answers nobody, and reads as working from its own unit test.
     */
    public function testAFileOnACardIsReportedAsAUsageOfTheDocument(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Le contenu illustré');
        $document = $this->givenDocument('Une photo');

        $this->attach($space, $item['id'], (int) $document->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $usages = static::getContainer()->get(DocumentUsageService::class)
            ->findUsages((int) $document->getId());

        $items = array_merge(...array_column($usages['groups'], 'items'));

        self::assertSame(1, $usages['total']);
        self::assertSame('studio.space_attachment', $items[0]['type']);
        self::assertSame('Le contenu illustré', $items[0]['label']);
        // The card title alone does not place the file: "Devis" exists in
        // every space, so the space is what the detail carries.
        self::assertStringContainsString('Espace de Client des fichiers', (string) $items[0]['detail']);
        self::assertStringContainsString((string) $space->getId(), (string) $items[0]['href']);
    }

    public function testADocumentNoCardCarriesIsReportedByNobody(): void
    {
        $document = $this->givenDocument('Une photo que personne n\'utilise');

        $usages = static::getContainer()->get(DocumentUsageService::class)
            ->findUsages((int) $document->getId());

        self::assertSame(0, $usages['total']);
    }

    /**
     * An upload is filed in the space's own folder, opened on first use.
     *
     * Until 2026-09-16 every space upload landed in one shared category and
     * nowhere else, so a studio with two customers had one undifferentiated
     * heap and nothing on a document said which space it came from. The folder
     * is the arrangement; the category only labels.
     */
    public function testAnUploadIsFiledInTheSpacesOwnFolder(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Un contenu à illustrer');

        $this->upload($space, $item['id'], 'photo.jpg');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $reloaded);

        $folder = $reloaded->getDocumentFolder();
        self::assertInstanceOf(DocumentFolder::class, $folder);
        self::assertSame($reloaded->getName(), $folder->getName());

        $document = $this->attachments->findForSpaceByItem($reloaded)[$item['id']][0]->getDocument();
        self::assertSame($folder->getId(), $document->getFolder()?->getId());
    }

    /** The folder is opened once, not per file. */
    public function testASecondUploadReusesTheSameFolder(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Un contenu à illustrer');

        $this->upload($space, $item['id'], 'une.jpg');
        $this->upload($space, $item['id'], 'deux.jpg');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $reloaded);

        $folders = [];

        foreach ($this->attachments->findForSpaceByItem($reloaded)[$item['id']] as $attachment) {
            $folders[] = $attachment->getDocument()->getFolder()?->getId();
        }

        self::assertCount(2, $folders);
        self::assertSame([$reloaded->getDocumentFolder()?->getId()], array_values(array_unique($folders)));
    }

    /**
     * Attaching a document that already lives in the library does not move it.
     *
     * The distinction that makes the arrangement safe: filing is something an
     * upload does on its way in. Relocating somebody's existing file because a
     * card referenced it would be a side effect nobody asked for, and the same
     * document can be attached to two spaces.
     */
    public function testAttachingAnExistingDocumentLeavesItWhereItIs(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Un contenu');
        $document = $this->givenDocument('Une photo déjà classée');

        self::assertNull($document->getFolder());

        $this->attach($space, $item['id'], (int) $document->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Document::class)->find($document->getId());
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertNull($reloaded->getFolder());
    }

    private function upload(CustomerSpace $space, int $itemId, string $name): void
    {
        $path = sys_get_temp_dir().'/aurora-space-upload-'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $this->jpegBytes());
        $this->tempFiles[] = $path;

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/content/%d/attachments/upload', $space->getId(), $itemId),
            [],
            ['file' => new UploadedFile($path, $name, 'image/jpeg', null, true)],
        );
    }

    /** The smallest thing the mime guesser calls a JPEG. */
    private function jpegBytes(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        );
    }

    private function attach(CustomerSpace $space, int $itemId, int $documentId): void
    {
        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/content/%d/attachments/attach', $space->getId(), $itemId),
            ['documentId' => $documentId],
        );
    }

    private function givenDocument(string $title, string $mimeType = 'image/jpeg'): Document
    {
        $category = $this->entityManager->getRepository(DocumentCategory::class)->findOneBy([]);

        $document = new Document();
        $document
            ->setTitle($title)
            ->setCategory($category)
            ->setFilePath('ged/2026/09/fixture.jpg')
            ->setFileName('fixture.jpg')
            ->setOriginalName($title.'.jpg')
            ->setMimeType($mimeType)
            ->setSize(1024);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    private function givenSpace(
        string $customerName = 'Client des fichiers',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('files@example.test');

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
    private function givenItem(CustomerSpace $space, string $title): array
    {
        $column = $this->columns->findForSpace($space)[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $column->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }

        self::fail(sprintf('the card "%s" was not in the answer', $title));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
