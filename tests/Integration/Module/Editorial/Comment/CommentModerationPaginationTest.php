<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Comment;

use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Comment\Repository\CommentRepository;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_map;
use function array_merge;
use function array_unique;
use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Every comment lands on exactly one page of the moderation list.
 *
 * The page query joined the post's translations, a collection, under its
 * LIMIT: SQL then limits rows of comment × translation, not comments. With a
 * post in three languages a page of 20 held about seven comments, the pages
 * did not add up to the total, and some comments were on none of them.
 */
final class CommentModerationPaginationTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private ?int $postId = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->postId) {
            $post = $this->entityManager->find(Post::class, $this->postId);
            if (null !== $post) {
                $this->entityManager->remove($post);
                $this->entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testTwentyFiveCommentsOnATrilingualPostFillTwoPages(): void
    {
        $tag = 'pagination-'.bin2hex(random_bytes(4));
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy([]);
        self::assertInstanceOf(PostType::class, $type);

        $post = new Post();
        $post->setPostType($type)->setStatus(PostStatusEnum::Published);
        foreach (['fr' => 'Article', 'en' => 'Post', 'es' => 'Artículo'] as $locale => $title) {
            $post->translate($locale)->setTitle($title)->setSlug(sprintf('%s-%s', $tag, $locale));
        }
        $this->entityManager->persist($post);

        for ($i = 1; $i <= 25; ++$i) {
            $comment = new Comment();
            $comment->setPost($post)->setAuthorName($tag)->setAuthorEmail('lecteur@example.test')->setContent('Commentaire '.$i)->setStatus(CommentStatusEnum::Pending);
            $this->entityManager->persist($comment);
        }
        $this->entityManager->flush();
        $this->postId = (int) $post->getId();
        $this->entityManager->clear();

        $repository = static::getContainer()->get(CommentRepository::class);
        $first = $repository->findPaginatedForAdmin(1, 20, null, $tag);
        $second = $repository->findPaginatedForAdmin(2, 20, null, $tag);

        self::assertSame(25, $first['total']);
        self::assertSame(2, $first['totalPages']);
        self::assertCount(20, $first['items']);
        self::assertCount(5, $second['items']);

        $ids = array_map(static fn (Comment $comment): int => (int) $comment->getId(), array_merge($first['items'], $second['items']));
        self::assertCount(25, array_unique($ids), 'no comment on two pages, none on neither');
    }
}
