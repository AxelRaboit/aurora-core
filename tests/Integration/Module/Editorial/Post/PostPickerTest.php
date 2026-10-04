<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_column;
use function array_reverse;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

/**
 * Re-opening a saved selection gives the posts back in the saved order.
 *
 * The ids were resolved with a plain `findBy`, in whatever order the database
 * chose and without the titles, which were then read post by post.
 */
final class PostPickerTest extends IntegrationTestCase
{
    /** @var list<int> */
    private array $ids = [];

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->ids as $id) {
            $post = $entityManager->find(Post::class, $id);
            if (null !== $post) {
                $entityManager->remove($post);
            }
        }

        $entityManager->flush();

        parent::tearDown();
    }

    public function testSavedIdsComeBackInTheirOrderWithTheirTitles(): void
    {
        $client = self::createClient();
        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $client->loginUser($admin, 'admin');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $type = $entityManager->getRepository(PostType::class)->findOneBy([]);
        $tag = bin2hex(random_bytes(4));

        foreach (['Premier', 'Deuxième', 'Troisième'] as $title) {
            $post = new Post();
            $post->setPostType($type)->setStatus(PostStatusEnum::Draft);
            $post->translate('fr')->setTitle($title)->setSlug(sprintf('%s-%s', mb_strtolower($title), $tag));
            $entityManager->persist($post);
            $entityManager->flush();
            $this->ids[] = (int) $post->getId();
        }

        $asked = array_reverse($this->ids);
        $client->request('GET', '/backend/editorial/posts/search?ids='.implode(',', $asked), server: ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $posts = json_decode((string) $client->getResponse()->getContent(), true)['posts'];
        self::assertSame($asked, array_column($posts, 'id'));
        self::assertSame(['Troisième', 'Deuxième', 'Premier'], array_column($posts, 'title'));
    }
}
