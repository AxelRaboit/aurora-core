<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function bin2hex;
use function random_bytes;

/**
 * Deleting a publication for good.
 *
 * The only irreversible operation in the application, and until now the only one
 * with no test of any kind - not the route, not the manager, not the bulk action
 * that reaches it. Everything else here can be undone by somebody who notices;
 * this cannot be undone by anybody.
 *
 * So the tests are about the two ways it goes wrong: it deletes something it should
 * not have, or it deletes something and leaves a piece of it behind.
 */
final class PostForceDeleteTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $admin;

    private PostType $postType;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;

        $this->postType = new PostType();
        $this->postType->setSlug('force-'.bin2hex(random_bytes(4)));
        $this->postType->setLabel('Force delete type');
        $this->entityManager->persist($this->postType);
        $this->entityManager->flush();
        $this->created[] = [PostType::class, (int) $this->postType->getId()];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            // Guarded: the tests here delete rows for good, and `remove()` nulls the
            // identifier on the object, so the id recorded at creation can be 0 by
            // the time this runs.
            if (0 === $id) {
                continue;
            }

            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testItRemovesTheRowForGood(): void
    {
        $post = $this->trashed();
        $id = $post->getId();

        $this->client->loginUser($this->admin, 'admin');
        $this->post('backend_editorial_posts_force_delete', $id);

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Post::class, $id), 'the row is gone, not soft-deleted again');
    }

    /**
     * Its translations go with it.
     *
     * A translation orphaned by a deleted post is a row nothing can reach and
     * nothing will ever clean up - and on a table with a unique slug per locale, it
     * is a slug permanently held by a page that does not exist.
     */
    public function testItTakesTheTranslationsWithIt(): void
    {
        $post = $this->trashed();
        $id = (int) $post->getId();

        self::assertNotSame(0, $this->translationCount($id));

        $this->client->loginUser($this->admin, 'admin');
        $this->post('backend_editorial_posts_force_delete', $id);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame(0, $this->translationCount($id));
    }

    /**
     * Somebody without the right cannot reach it.
     *
     * `editorial.posts.delete` guards the route and the voter guards the record.
     * This is the assertion that matters most on an operation with no undo.
     */
    public function testSomebodyWithoutTheRightCannotDeleteForGood(): void
    {
        $post = $this->trashed();
        $id = $post->getId();

        $writer = $this->account(['editorial.posts.view', 'editorial.posts.edit']);

        $this->client->loginUser($writer, 'admin');
        $this->post('backend_editorial_posts_force_delete', $id);

        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Post::class, $id), 'still there');
    }

    /**
     * Emptying the trash takes the trash and nothing else.
     *
     * The one that would be catastrophic and silent: a missing `deletedAt IS NOT
     * NULL` deletes the whole site, and the endpoint answers `success` either way
     * because it only reports how many it removed.
     */
    public function testEmptyingTheTrashLeavesLivePostsAlone(): void
    {
        $trashed = $this->trashed();
        $live = $this->draft();

        // Captured before the call: `remove()` nulls the identifier on the object
        // it removed, so reading it afterwards asks Doctrine to find a post with no
        // id.
        $trashedId = (int) $trashed->getId();
        $liveId = (int) $live->getId();

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('backend_editorial_posts_empty_trash'));

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $body['deleted']);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Post::class, $trashedId));
        self::assertNotNull(
            $this->entityManager->find(Post::class, $liveId),
            'a published post is not in the trash and must survive emptying it',
        );
    }

    /** And the bulk action reaches the same place, with the same guard. */
    public function testTheBulkActionDeletesOnlyTrashedRows(): void
    {
        $trashed = $this->trashed();
        $live = $this->draft();
        $trashedId = (int) $trashed->getId();
        $liveId = (int) $live->getId();

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_editorial_posts_bulk'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'action' => 'force_delete',
                'ids' => [$trashedId, $liveId],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['done'], 'only the trashed one');
        self::assertSame(1, $body['skipped'], 'the live one is refused, not deleted');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Post::class, $trashedId));
        self::assertNotNull($this->entityManager->find(Post::class, $liveId));
    }

    private function translationCount(int $postId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM core_post_translations WHERE post_id = ?',
            [$postId],
        );
    }

    private function post(string $route, ?int $id): void
    {
        $this->client->request('POST', $this->urlGenerator->generate($route, ['id' => $id]));
    }

    /** @param list<string> $privileges */
    private function account(array $privileges): User
    {
        $user = new User();
        $user->setEmail('force-'.bin2hex(random_bytes(4)).'@aurora.test');
        $user->setName('Rédacteur');
        $user->setType(UserTypeEnum::Backend);
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER']);
        $user->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->created[] = [User::class, (int) $user->getId()];

        return $user;
    }

    private function draft(): PostInterface
    {
        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $this->postType->getId(),
                'status' => 'draft',
                'translations' => ['fr' => ['title' => 'À garder '.bin2hex(random_bytes(3))]],
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }

    private function trashed(): PostInterface
    {
        $post = $this->draft();
        static::getContainer()->get(PostManagerInterface::class)->delete($post);
        $this->entityManager->flush();

        return $post;
    }
}
