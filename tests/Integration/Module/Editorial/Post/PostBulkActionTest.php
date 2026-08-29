<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
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
 * Acting on a selection.
 *
 * The interesting behaviour is not that ten posts change. It is what happens when
 * only eight of them may: a list mixes authors, so a partly-refused selection is
 * the ordinary case rather than the edge one, and how that is reported is the whole
 * design.
 */
final class PostBulkActionTest extends IntegrationTestCase
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
        $this->postType->setSlug('bulk-'.bin2hex(random_bytes(4)));
        $this->postType->setLabel('Bulk type');
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

    public function testPublishingASelectionPublishesAndDatesThem(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->client->loginUser($this->admin, 'admin');
        $body = $this->bulk('publish', [$first->getId(), $second->getId()]);

        self::assertSame(2, $body['done']);
        self::assertSame(0, $body['skipped']);

        $this->entityManager->clear();

        foreach ([$first, $second] as $post) {
            $stored = $this->entityManager->find(Post::class, $post->getId());
            self::assertInstanceOf(Post::class, $stored);
            self::assertSame(PostStatusEnum::Published, $stored->getStatus());
            // Dated, or the front and the sitemap would order it nowhere.
            self::assertNotNull($stored->getPublishedAt());
        }
    }

    /**
     * The refused ones are counted, not hidden and not fatal.
     *
     * Refusing the whole request over two rows makes the feature useless on exactly
     * the mixed lists it is for; doing the rest silently would have the reader
     * believe all of it changed. So: both numbers.
     */
    public function testAPartlyRefusedSelectionDoesTheRestAndSaysSo(): void
    {
        $mine = $this->draft();
        $theirs = $this->draft();
        $theirs->setAuthor($this->otherAuthor());
        $this->entityManager->flush();

        // An author who may manage their own posts and publish nothing.
        $writer = $this->account(['editorial.posts.view', 'editorial.posts.edit', 'editorial.posts.manage']);
        $mine->setAuthor($writer);
        $this->entityManager->flush();

        $this->client->loginUser($writer, 'admin');
        $body = $this->bulk('draft', [$mine->getId(), $theirs->getId()]);

        self::assertSame(1, $body['done'], 'the one they own');
        self::assertSame(1, $body['skipped'], 'the one they do not');
    }

    /**
     * An id that matches nothing is counted too.
     *
     * A selection carrying a post somebody deleted a moment ago should say so,
     * rather than come back reporting fewer rows than were sent with no
     * explanation.
     */
    public function testAnIdThatMatchesNothingIsReportedAsSkipped(): void
    {
        $post = $this->draft();

        $this->client->loginUser($this->admin, 'admin');
        $body = $this->bulk('publish', [$post->getId(), 999_999_999]);

        self::assertSame(1, $body['done']);
        self::assertSame(1, $body['skipped']);
    }

    /**
     * Restoring a post that is not in the trash does nothing, and says nothing
     * happened.
     *
     * Doing something surprising is worse than doing nothing, and reporting a
     * success for a no-op is how a reader learns to distrust the count.
     */
    public function testAnActionAimedAtTheWrongSideIsSkipped(): void
    {
        $live = $this->draft();

        $this->client->loginUser($this->admin, 'admin');
        $body = $this->bulk('restore', [$live->getId()]);

        self::assertSame(0, $body['done']);
        self::assertSame(1, $body['skipped']);
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->bulk('set_fire_to', [1]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->bulk('publish', []);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param list<int|null> $ids
     *
     * @return array<string, mixed>
     */
    private function bulk(string $action, array $ids): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_editorial_posts_bulk'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => $action, 'ids' => $ids], JSON_THROW_ON_ERROR),
        );

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param list<string> $privileges */
    private function account(array $privileges): User
    {
        $user = new User();
        $user->setEmail('bulk-'.bin2hex(random_bytes(4)).'@aurora.test');
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

    private function otherAuthor(): User
    {
        return $this->account(['editorial.posts.view']);
    }

    private function draft(): PostInterface
    {
        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $this->postType->getId(),
                'status' => 'draft',
                'translations' => ['fr' => ['title' => 'Lot '.bin2hex(random_bytes(3))]],
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
