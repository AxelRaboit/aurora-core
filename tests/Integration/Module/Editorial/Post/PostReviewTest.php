<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Core\Notification\Repository\NotificationRepository;
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
 * The review workflow, which used to be a status and a badge colour.
 *
 * A post was demoted into `pending_review` silently, nobody was told, and the way
 * out was for somebody to notice. These tests are about the halves that were
 * missing: somebody hears, somebody can decide, and the author learns why.
 */
final class PostReviewTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $author;

    private User $reviewer;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $users = static::getContainer()->get(UserRepository::class);

        $admin = $users->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);

        // An author who may write and not publish, which is the whole reason this
        // workflow exists.
        $this->author = $this->account('auteur', ['editorial.posts.view', 'editorial.posts.edit', 'editorial.posts.manage'], $admin);
        // And somebody appointed to decide, without being an administrator.
        $this->reviewer = $this->account('relecteur', ['editorial.posts.view', 'editorial.posts.edit', 'editorial.posts.publish'], $admin);
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
     * Publishing without the right puts it in the queue **and tells somebody**.
     *
     * The demotion already worked. The notification is the half that was missing,
     * and without it the queue is one nobody clears.
     */
    public function testAnAuthorPublishingSendsItForReviewAndNotifiesTheReviewer(): void
    {
        $post = $this->draft();

        $this->client->loginUser($this->author, 'admin');
        $this->post('backend_editorial_posts_update', $post, [
            'postTypeId' => $post->getPostType()->getId(),
            'status' => 'published',
            'translations' => ['fr' => ['title' => 'Article à relire']],
        ]);

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);
        self::assertSame(PostStatusEnum::PendingReview, $stored->getStatus());

        self::assertNotSame([], $this->notificationsFor($this->reviewer, 'editorial.post.review_requested'));
    }

    /** Approving publishes it, and dates it. */
    public function testApprovingPublishesAndTellsTheAuthor(): void
    {
        $post = $this->pending();

        $this->client->loginUser($this->reviewer, 'admin');
        $this->post('backend_editorial_posts_review_approve', $post);

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(PostStatusEnum::Published, $stored->getStatus());
        // Dated, or the front and the sitemap would order it nowhere.
        self::assertNotNull($stored->getPublishedAt());
        self::assertNotNull($stored->getReviewedAt());

        self::assertNotSame([], $this->notificationsFor($this->author, 'editorial.post.review_approved'));
    }

    /**
     * Rejecting sends it back **with the reason**, which is the point.
     *
     * Back to `draft` rather than a `rejected` status: the author's next move is to
     * edit, and a status meaning only "edit this" is a second word for draft.
     */
    public function testRejectingSendsItBackWithTheReason(): void
    {
        $post = $this->pending();

        $this->client->loginUser($this->reviewer, 'admin');
        $this->post('backend_editorial_posts_review_reject', $post, ['note' => 'Il manque la source du chiffre.']);

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(PostStatusEnum::Draft, $stored->getStatus());
        self::assertSame('Il manque la source du chiffre.', $stored->getReviewNote());
        self::assertNotSame([], $this->notificationsFor($this->author, 'editorial.post.review_rejected'));
    }

    /**
     * A rejection with no reason is refused.
     *
     * A note-less rejection leaves the author guessing at what to change, which is
     * the whole thing a review is meant to save them.
     */
    public function testARejectionNeedsAReason(): void
    {
        $post = $this->pending();

        $this->client->loginUser($this->reviewer, 'admin');
        $this->post('backend_editorial_posts_review_reject', $post, ['note' => '   ']);

        self::assertResponseStatusCodeSame(422);
    }

    /** Somebody without the grant cannot approve their own way out of the queue. */
    public function testAnAuthorCannotApproveTheirOwnPost(): void
    {
        $post = $this->pending();

        $this->client->loginUser($this->author, 'admin');
        $this->post('backend_editorial_posts_review_approve', $post);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Resubmitting clears the last decision.
     *
     * A note about a version that no longer exists sends the reviewer looking for a
     * problem already fixed.
     */
    public function testResubmittingClearsTheOldFeedback(): void
    {
        $post = $this->pending();

        $this->client->loginUser($this->reviewer, 'admin');
        $this->post('backend_editorial_posts_review_reject', $post, ['note' => 'À revoir.']);

        $this->client->loginUser($this->author, 'admin');
        $this->post('backend_editorial_posts_update', $post, [
            'postTypeId' => $post->getPostType()->getId(),
            'status' => 'published',
            'translations' => ['fr' => ['title' => 'Corrigé']],
        ]);

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(PostStatusEnum::PendingReview, $stored->getStatus());
        self::assertNull($stored->getReviewNote());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $route, PostInterface $post, array $payload = []): void
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate($route, ['id' => $post->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<object> */
    private function notificationsFor(User $user, string $type): array
    {
        return static::getContainer()->get(NotificationRepository::class)
            ->findBy(['recipient' => $user, 'type' => $type]);
    }

    /** @param list<string> $privileges */
    private function account(string $name, array $privileges, User $template): User
    {
        $user = new User();
        $user->setEmail(sprintf('%s-%s@aurora.test', $name, bin2hex(random_bytes(3))));
        $user->setName($name);
        $user->setType(UserTypeEnum::Backend);
        $user->setPassword('x');
        // The demo admin's roles, minus the privileges - so the difference under
        // test is the grant and not the role.
        $user->setRoles(['ROLE_USER']);
        $user->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->created[] = [User::class, (int) $user->getId()];

        return $user;
    }

    private function draft(): PostInterface
    {
        $postType = new PostType();
        $postType->setSlug('review-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Review type');
        $this->entityManager->persist($postType);
        $this->entityManager->flush();
        $this->created[] = [PostType::class, (int) $postType->getId()];

        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'draft',
                'translations' => ['fr' => ['title' => 'Brouillon']],
            ]),
        );
        $post->setAuthor($this->author);
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }

    private function pending(): PostInterface
    {
        $post = $this->draft();
        $post->setStatus(PostStatusEnum::PendingReview);
        $this->entityManager->flush();

        return $post;
    }
}
