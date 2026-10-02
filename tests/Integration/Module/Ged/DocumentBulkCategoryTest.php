<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_reverse;
use function bin2hex;
use function json_decode;
use function random_bytes;

/**
 * Filing a selection under one category in a single gesture.
 *
 * Three promises: the alternates follow their original when asked, so a
 * family is never split across two categories; an empty choice files the
 * selection under none; and a category that does not exist, or sits in the
 * trash, is refused instead of being read as "none", which would quietly
 * empty the category of everything selected.
 */
final class DocumentBulkCategoryTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());

            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testTheAlternatesFollowTheirOriginalWhenAsked(): void
    {
        $category = $this->givenCategory();
        $original = $this->givenDocument();
        $alternate = $this->givenDocument($original);
        $other = $this->givenDocument();

        $data = $this->send(['ids' => [$original->getId()], 'categoryId' => $category->getId(), 'withAlternates' => true]);

        self::assertSame(2, $data['categorized']);
        self::assertSame($category->getId(), $this->categoryOf($original));
        self::assertSame($category->getId(), $this->categoryOf($alternate));
        self::assertNull($this->categoryOf($other), 'a document outside the selection is left alone');
    }

    public function testAnEmptyChoiceFilesTheSelectionUnderNone(): void
    {
        $category = $this->givenCategory();
        $document = $this->givenDocument();
        $this->send(['ids' => [$document->getId()], 'categoryId' => $category->getId()]);

        $data = $this->send(['ids' => [$document->getId()], 'categoryId' => null]);

        self::assertSame(1, $data['categorized']);
        self::assertNull($this->categoryOf($document));
    }

    public function testAnUnknownOrTrashedCategoryIsRefusedAndNothingMoves(): void
    {
        $kept = $this->givenCategory();
        $document = $this->givenDocument();
        $this->send(['ids' => [$document->getId()], 'categoryId' => $kept->getId()]);

        $trashed = $this->givenCategory();
        $trashed->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        foreach ([999_999_999, $trashed->getId()] as $categoryId) {
            $this->client->jsonRequest('POST', '/backend/ged/documents/bulk-category', ['ids' => [$document->getId()], 'categoryId' => $categoryId]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame($kept->getId(), $this->categoryOf($document));
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function send(array $payload): array
    {
        $this->client->jsonRequest('POST', '/backend/ged/documents/bulk-category', $payload);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function categoryOf(Document $document): ?int
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->find(Document::class, $document->getId());
        self::assertInstanceOf(Document::class, $fresh);

        return $fresh->getCategory()?->getId();
    }

    private function givenCategory(): DocumentCategory
    {
        $suffix = bin2hex(random_bytes(4));
        $category = new DocumentCategory();
        $category->setName('Rubrique '.$suffix)->setSlug('rubrique-'.$suffix);

        return $this->persist($category);
    }

    private function givenDocument(?Document $original = null): Document
    {
        $document = new Document();
        $document
            ->setTitle('Visuel '.bin2hex(random_bytes(4)))
            ->setOriginalName('visuel.png')
            ->setFilePath('ged/2026/10/'.bin2hex(random_bytes(8)).'.png')
            ->setMimeType('image/png');

        if ($original instanceof Document) {
            $managed = $this->entityManager->find(Document::class, $original->getId());
            $document->setOriginal($managed)->setAlternateLabel('Variante');
        }

        return $this->persist($document);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persist(object $entity): object
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;

        return $entity;
    }
}
