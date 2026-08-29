<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Preview\Manager\PostPreviewTokenManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function str_repeat;

/**
 * Looking at a publication before anybody else can.
 *
 * The only route in this application that serves an unpublished page, so the tests
 * are about exactly that: it works without an account, it stops working on its own,
 * and it says loudly that it must not be kept or indexed.
 */
final class PostPreviewTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private PostPreviewTokenManagerInterface $previews;

    private User $admin;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
        $this->previews = static::getContainer()->get(PostPreviewTokenManagerInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
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
     * The whole point: a draft is not on the site, and is on this address.
     *
     * Both halves in one test, because either alone proves nothing - a page that
     * renders might also be public, and a 404 on the front might just be a wrong
     * slug.
     */
    public function testADraftIsInvisibleOnTheSiteAndVisibleOnItsPreview(): void
    {
        $post = $this->draft('Brouillon secret');

        $this->client->request('GET', '/fr/preview-type/brouillon-secret');
        self::assertResponseStatusCodeSame(404);

        $token = $this->previews->resolveOrCreate($post, $this->admin);

        $this->client->request('GET', $this->previewUrl($token->getToken()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Brouillon secret', (string) $this->client->getResponse()->getContent());
    }

    /**
     * No account, which is the reason this exists rather than a backend screen.
     *
     * A reviewer or a client is exactly the person who cannot sign in, and a preview
     * behind the firewall would leave them where they were.
     */
    public function testItOpensWithoutSigningIn(): void
    {
        $token = $this->previews->resolveOrCreate($this->draft(), $this->admin);

        // No `loginUser` anywhere in this test.
        $this->client->request('GET', $this->previewUrl($token->getToken()));

        self::assertResponseIsSuccessful();
    }

    /**
     * An unpublished page behind a secret must not be kept or found.
     *
     * A shared cache would serve it to whoever asks next, and a crawler that saw the
     * address in a referrer would publish it on the owner's behalf.
     */
    public function testItRefusesToBeCachedOrIndexed(): void
    {
        $token = $this->previews->resolveOrCreate($this->draft(), $this->admin);

        $this->client->request('GET', $this->previewUrl($token->getToken()));

        $headers = $this->client->getResponse()->headers;

        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
    }

    /** It closes on its own, which is what makes handing it out acceptable. */
    public function testAnExpiredPreviewStopsWorking(): void
    {
        $token = $this->previews->resolveOrCreate($this->draft(), $this->admin);
        $token->setExpiresAt(new DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->client->request('GET', $this->previewUrl($token->getToken()));

        self::assertResponseStatusCodeSame(404);
    }

    /** A token nobody minted answers exactly like one that has run out. */
    public function testAnUnknownTokenIsNotFound(): void
    {
        $this->client->request('GET', $this->previewUrl(str_repeat('a', 64)));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Pressing the button twice hands back the same address.
     *
     * A button that mints a new secret each time leaves a trail of working links
     * behind, and the person pressing it has no idea they are accumulating.
     */
    public function testAskingTwiceReturnsTheSameAddress(): void
    {
        $post = $this->draft();

        $first = $this->previews->resolveOrCreate($post, $this->admin);
        $second = $this->previews->resolveOrCreate($post, $this->admin);

        self::assertSame($first->getToken(), $second->getToken());
    }

    /** And revoking ends it, for a draft that should stop being visible. */
    public function testRevokingEndsThePreview(): void
    {
        $post = $this->draft();
        $token = $this->previews->resolveOrCreate($post, $this->admin);
        $url = $this->previewUrl($token->getToken());

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->previews->revoke($post);

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The backend route mints it, and needs the right to edit rather than to
     * publish.
     *
     * Requiring the right to publish would leave exactly the person who needs the
     * link - somebody asking for a review - unable to make one.
     */
    public function testTheBackendRouteHandsBackAnAddress(): void
    {
        $post = $this->draft();

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_editorial_posts_preview', ['id' => $post->getId()]),
        );

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsString($body['url']);
        self::assertStringContainsString('/preview/', $body['url']);
        self::assertNotNull($body['expiresAt']);
    }

    private function previewUrl(string $token): string
    {
        return $this->urlGenerator->generate('editorial_post_preview_show', ['token' => $token]);
    }

    private function draft(string $title = 'Brouillon'): PostInterface
    {
        $postType = new PostType();
        $postType->setSlug('preview-type');
        $postType->setLabel('Preview type');
        $this->entityManager->persist($postType);
        $this->entityManager->flush();
        $this->created[] = [PostType::class, (int) $postType->getId()];

        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'draft',
                'translations' => ['fr' => ['title' => $title]],
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
