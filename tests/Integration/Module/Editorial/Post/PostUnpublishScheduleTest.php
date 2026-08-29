<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Message\UnpublishScheduledPostsMessage;
use Aurora\Module\Editorial\Post\MessageHandler\UnpublishScheduledPostsHandler;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function bin2hex;
use function random_bytes;

/**
 * Taking a publication down on a date.
 *
 * `scheduledAt` put posts live without anybody present and nothing took them off
 * again, so an offer that expired relied on somebody remembering. These tests are
 * about the job doing it, and about the three cases where it must not.
 */
final class PostUnpublishScheduleTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private UnpublishScheduledPostsHandler $handler;

    private PostType $postType;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(UnpublishScheduledPostsHandler::class);

        $this->postType = new PostType();
        $this->postType->setSlug('unpub-'.bin2hex(random_bytes(4)));
        $this->postType->setLabel('Unpublish type');
        $this->entityManager->persist($this->postType);
        $this->entityManager->flush();
        $this->created[] = [PostType::class, (int) $this->postType->getId()];
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

    /**
     * A live post past its end date is archived, and the date is cleared.
     *
     * `Archived` rather than `Draft`: it was finished and public, and draft would
     * say somebody is still working on it - putting it back in the queue an author
     * scans for things to finish.
     *
     * Clearing the date matters as much: left in place, the job would find the same
     * post on every tick for ever.
     */
    public function testAPostPastItsEndDateComesDown(): void
    {
        $post = $this->published(new DateTimeImmutable('-1 minute'));

        ($this->handler)(new UnpublishScheduledPostsMessage());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(PostStatusEnum::Archived, $stored->getStatus());
        self::assertNull($stored->getUnpublishAt());
    }

    public function testAPostWhoseDateHasNotArrivedStaysUp(): void
    {
        $post = $this->published(new DateTimeImmutable('+1 hour'));

        ($this->handler)(new UnpublishScheduledPostsMessage());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(PostStatusEnum::Published, $stored->getStatus());
        self::assertNotNull($stored->getUnpublishAt());
    }

    /**
     * A draft carrying an old end date is left alone.
     *
     * The query asks for `published` specifically. Archiving a draft would move a
     * post somebody is still working on, on the strength of a date that never
     * applied to it.
     */
    public function testADraftWithAnOldDateIsLeftAlone(): void
    {
        $post = $this->published(new DateTimeImmutable('-1 hour'));
        $post->setStatus(PostStatusEnum::Draft);
        $this->entityManager->flush();

        ($this->handler)(new UnpublishScheduledPostsMessage());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(PostStatusEnum::Draft, $stored->getStatus());
        self::assertNotNull($stored->getUnpublishAt(), 'the date is the author\'s, and untouched');
    }

    /** A post with no end date is never touched, which is almost all of them. */
    public function testAPostWithNoEndDateIsNeverTouched(): void
    {
        $post = $this->published(null);

        ($this->handler)(new UnpublishScheduledPostsMessage());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(PostStatusEnum::Published, $stored->getStatus());
    }

    /**
     * The date survives an ordinary save.
     *
     * `scheduledAt` is cleared whenever the status is not `scheduled`; this one must
     * not be, or editing a live post would silently drop the takedown somebody set.
     */
    public function testAnOrdinarySaveKeepsTheEndDate(): void
    {
        $post = $this->published(new DateTimeImmutable('+2 days'));

        static::getContainer()->get(PostManagerInterface::class)->update(
            $post,
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $this->postType->getId(),
                'status' => 'published',
                'unpublishAt' => $post->getUnpublishAt()?->format('Y-m-d\TH:i'),
                'translations' => ['fr' => ['title' => 'Titre modifié']],
            ]),
        );
        $this->entityManager->flush();

        self::assertNotNull($post->getUnpublishAt());
    }

    private function published(?DateTimeImmutable $unpublishAt): PostInterface
    {
        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $this->postType->getId(),
                'status' => 'published',
                'translations' => ['fr' => ['title' => 'Offre limitée']],
            ]),
        );
        $post->setUnpublishAt($unpublishAt);
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
