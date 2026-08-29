<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Duplicate\PostDuplicator;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function bin2hex;
use function random_bytes;

/**
 * Copying a publication.
 *
 * The assertions that matter are about what is *not* carried over. Copying the
 * text is the easy half and would be noticed immediately if it broke; copying the
 * publication date, or the review history, or the canonical URL, is the half that
 * would ship quietly and put a second page on the site claiming to be the first.
 */
final class PostDuplicateTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private PostDuplicator $duplicator;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->duplicator = static::getContainer()->get(PostDuplicator::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** The content comes across, in every language. */
    public function testItCopiesTheContentOfEveryLanguage(): void
    {
        $source = $this->published();

        $copy = $this->duplicator->duplicate($source);
        $this->created[] = [Post::class, (int) $copy->getId()];

        self::assertNotSame($source->getId(), $copy->getId());
        self::assertStringContainsString('Article original', (string) $copy->getTranslation('fr')?->getTitle());
        self::assertStringContainsString('Original article', (string) $copy->getTranslation('en')?->getTitle());
        self::assertSame('Description originale', $copy->getTranslation('fr')?->getDescription());
        self::assertSame($source->getPostType()->getId(), $copy->getPostType()->getId());
    }

    /**
     * A copy is a draft, dated nowhere, with no review history.
     *
     * The single most important assertion here is the status: a duplicate that
     * published itself would put a page live on the site that nobody wrote.
     */
    public function testACopyIsADraftWithNoHistory(): void
    {
        $source = $this->published();
        $source->setReviewNote('Il manque la source.');
        $source->setReviewedAt(new DateTimeImmutable());
        $source->setScheduledAt(new DateTimeImmutable('+1 day'));
        $this->entityManager->flush();

        $copy = $this->duplicator->duplicate($source);
        $this->created[] = [Post::class, (int) $copy->getId()];

        self::assertSame(PostStatusEnum::Draft, $copy->getStatus());
        self::assertNull($copy->getPublishedAt());
        self::assertNull($copy->getScheduledAt());
        self::assertNull($copy->getReviewNote());
        self::assertNull($copy->getReviewedAt());
    }

    /**
     * The title says which is which, and the slug follows.
     *
     * Two rows with the same title is a list where somebody edits the wrong one,
     * and two posts on one slug is a page that resolves to whichever was found
     * first.
     */
    public function testTheCopyIsToldApartFromTheOriginal(): void
    {
        $source = $this->published();

        $copy = $this->duplicator->duplicate($source);
        $this->created[] = [Post::class, (int) $copy->getId()];

        self::assertNotSame(
            $source->getTranslation('fr')?->getTitle(),
            $copy->getTranslation('fr')?->getTitle(),
        );
        self::assertNotSame(
            $source->getTranslation('fr')?->getSlug(),
            $copy->getTranslation('fr')?->getSlug(),
        );
        // And its own reference, or an invoice quoting one would name two posts.
        self::assertNotSame($source->getReference(), $copy->getReference());
    }

    /**
     * The canonical URL is left behind.
     *
     * It points at the original's address, and a copy announcing itself as that
     * page is the one mistake here that would actually cost something: search
     * engines would treat the draft as the canonical version of a live page.
     */
    public function testItDoesNotClaimToBeTheOriginal(): void
    {
        $source = $this->published();
        $source->getTranslation('fr')?->setCanonicalUrl('https://example.test/fr/article-original');
        $this->entityManager->flush();

        $copy = $this->duplicator->duplicate($source);
        $this->created[] = [Post::class, (int) $copy->getId()];

        self::assertNull($copy->getTranslation('fr')?->getCanonicalUrl());
    }

    /** The design comes across whole, which is most of why anybody duplicates. */
    public function testItCopiesTheLayouts(): void
    {
        $source = $this->published();
        $source->setGalleryLayout(['enabled' => true, 'layout' => 'masonry', 'columns' => 4, 'ratio' => 'natural', 'items' => []]);
        $this->entityManager->flush();

        $copy = $this->duplicator->duplicate($source);
        $this->created[] = [Post::class, (int) $copy->getId()];

        self::assertTrue($copy->getGalleryLayout()['enabled']);
        self::assertSame('masonry', $copy->getGalleryLayout()['layout']);
        self::assertSame(4, $copy->getGalleryLayout()['columns']);
    }

    private function published(): PostInterface
    {
        $postType = new PostType();
        $postType->setSlug('dup-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Duplicate type');
        $this->entityManager->persist($postType);
        $this->entityManager->flush();
        $this->created[] = [PostType::class, (int) $postType->getId()];

        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'published',
                'translations' => [
                    'fr' => ['title' => 'Article original', 'description' => 'Description originale'],
                    'en' => ['title' => 'Original article'],
                ],
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
