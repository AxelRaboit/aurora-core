<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
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

use function json_decode;
use function sprintf;

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
