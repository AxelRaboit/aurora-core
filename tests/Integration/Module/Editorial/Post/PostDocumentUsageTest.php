<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_column;
use function array_merge;
use function bin2hex;
use function random_bytes;

/**
 * Deleting a picture says which posts were drawing it.
 *
 * A post holds a document in three unrelated places - its cover, each
 * translation's social image, and the ids inside its gallery - and none of
 * them was reported. The cover and the social image are `SET NULL`, so
 * deleting the file blanks them; the gallery id is not a relation at all, so
 * nothing blanks and the slot simply draws nothing. In all three cases the
 * deletion screen said the file was used by nobody.
 *
 * Through the aggregator, because a provider that is written but not tagged
 * answers nobody while passing its own unit test.
 */
final class PostDocumentUsageTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testACoverIsReportedAsAUsage(): void
    {
        $picture = $this->givenDocument();
        $post = $this->givenPost('Un billet illustré', static function (PostInterface $post) use ($picture): void {
            $post->setThumbnail($picture);
        });

        $items = $this->usagesOf($picture);

        self::assertCount(1, $items);
        self::assertSame('editorial.post', $items[0]['type']);
        self::assertSame('Un billet illustré', $items[0]['label']);
        self::assertStringContainsString((string) $post->getId(), (string) $items[0]['href']);
    }

    public function testASocialImageIsReportedAsAUsage(): void
    {
        $picture = $this->givenDocument();
        $this->givenPost('Un billet partagé', static function (PostInterface $post) use ($picture): void {
            $post->translate('fr')->setOgImage($picture);
        });

        self::assertCount(1, $this->usagesOf($picture));
    }

    /**
     * The gallery holds ids rather than relations, so nothing cascades and
     * nothing blanks: the slot is simply empty after the deletion.
     */
    public function testAGalleryPictureIsReportedAsAUsage(): void
    {
        $picture = $this->givenDocument();
        $this->givenPost('Un billet avec galerie', static function (PostInterface $post) use ($picture): void {
            $post->setGalleryLayout(['items' => [['id' => 'g1', 'mediaId' => (int) $picture->getId()]]]);
        });

        self::assertCount(1, $this->usagesOf($picture));
    }

    /**
     * A post that uses the same picture twice is reported once.
     *
     * The three pointers are independent, so a cover reused as the first
     * gallery item matches twice. What the person deleting needs is the list
     * of things that break, and that is one post.
     */
    public function testAPostUsingThePictureTwiceIsReportedOnce(): void
    {
        $picture = $this->givenDocument();
        $this->givenPost('Un billet qui se répète', static function (PostInterface $post) use ($picture): void {
            $post->setThumbnail($picture);
            $post->setGalleryLayout(['items' => [['id' => 'g1', 'mediaId' => (int) $picture->getId()]]]);
        });

        self::assertCount(1, $this->usagesOf($picture));
    }

    /**
     * The SQL narrowing is a substring match, so it is not the answer.
     *
     * Asked about document 7, `LIKE '%"mediaId":7%'` also matches a gallery
     * holding 70 or 777. Reporting those would be worse than reporting
     * nothing: it would talk somebody out of a deletion that was safe.
     */
    public function testAPictureWhoseIdIsAPrefixOfAnotherIsNotReported(): void
    {
        $used = $this->givenDocument();
        $this->givenPost('Un billet avec une autre image', static function (PostInterface $post) use ($used): void {
            $post->setGalleryLayout(['items' => [['id' => 'g1', 'mediaId' => (int) $used->getId()]]]);
        });

        // Nothing carries this id, but it is a prefix of the one that is used.
        $prefix = (int) ((string) $used->getId())[0];

        if ($prefix === (int) $used->getId()) {
            self::markTestSkipped('the fixture id is a single digit, so there is no prefix to confuse');
        }

        self::assertSame([], $this->usagesOf($prefix));
    }

    public function testAPictureNoPostDrawsIsReportedByNobody(): void
    {
        self::assertSame([], $this->usagesOf($this->givenDocument()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usagesOf(DocumentInterface|int $document): array
    {
        $id = $document instanceof DocumentInterface ? (int) $document->getId() : $document;

        $usages = static::getContainer()->get(DocumentUsageService::class)->findUsages($id);

        if ([] === $usages['groups']) {
            return [];
        }

        return array_merge(...array_column($usages['groups'], 'items'));
    }

    private function givenDocument(): DocumentInterface
    {
        $document = new Document();
        $document
            ->setTitle('Photo '.bin2hex(random_bytes(4)))
            ->setOriginalName('photo.jpg')
            ->setFilePath('ged/2026/09/'.bin2hex(random_bytes(8)).'.jpg')
            ->setMimeType('image/jpeg');

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->created[] = $document;

        return $document;
    }

    private function givenPost(string $title, callable $decorate): PostInterface
    {
        $suffix = bin2hex(random_bytes(4));

        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'usage-type']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('usage-type')->setLabel('Usage type')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
            $this->entityManager->flush();
            $this->created[] = $type;
        }

        $post = new Post();
        $post->setPostType($type)->setStatus(PostStatusEnum::Draft);
        $post->translate('fr')->setTitle($title)->setSlug('usage-'.$suffix);

        $decorate($post);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;

        return $post;
    }
}
