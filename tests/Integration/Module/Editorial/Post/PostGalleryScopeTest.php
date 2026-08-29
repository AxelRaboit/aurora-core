<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostGalleryInputFactoryInterface;
use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostGalleryManagerInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function bin2hex;
use function random_bytes;

/**
 * The gallery path may change a gallery, and must not be able to change anything
 * else.
 *
 * This is the test the whole feature rests on. The screen offering no title field
 * is a courtesy; the guarantee is that `PostGalleryInput` cannot express one and
 * `PostGalleryManager` does not write one. Sending a contributor to
 * `/posts/4/edit#gallery` instead would have restricted nothing at all - a
 * fragment picks a tab in the browser, and `posts_update` accepts seventeen
 * fields - so what is asserted here is exactly the difference between the two
 * designs.
 *
 * Written as an integration test rather than a unit one because the claim is
 * about what reaches the database. A mocked manager would prove the input object
 * has two properties, which was never in doubt.
 */
final class PostGalleryScopeTest extends IntegrationTestCase
{
    private PostManagerInterface $postManager;

    private PostInputFactoryInterface $postInputFactory;

    private PostGalleryManagerInterface $galleryManager;

    private PostGalleryInputFactoryInterface $galleryInputFactory;

    private EntityManagerInterface $entityManager;

    private KernelBrowser $client;

    private UrlGeneratorInterface $urlGenerator;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        // A client rather than a bare kernel: the tests added below go through the
        // routes, which is where the permission lives - the payload's narrowness is
        // only half the boundary.
        $this->client = static::createClient();

        $container = static::getContainer();
        $this->urlGenerator = $container->get(UrlGeneratorInterface::class);
        $this->postManager = $container->get(PostManagerInterface::class);
        $this->postInputFactory = $container->get(PostInputFactoryInterface::class);
        $this->galleryManager = $container->get(PostGalleryManagerInterface::class);
        $this->galleryInputFactory = $container->get(PostGalleryInputFactoryInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
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
     * Everything a contributor should not be able to reach, sent in one payload.
     *
     * Each of these is a field the full editor accepts and this path must ignore.
     * They are asserted together on purpose: a regression here would most likely
     * come from someone widening the factory, and that widens all of them at once.
     */
    public function testAGalleryWriteCannotTouchTheRestOfThePost(): void
    {
        $post = $this->publishedPost();

        $before = [
            'status' => $post->getStatus()->value,
            'title' => $post->getTranslation('fr')?->getTitle(),
            'slug' => $post->getTranslation('fr')?->getSlug(),
            'postTypeId' => $post->getPostType()->getId(),
            'commentsEnabled' => $post->isCommentsEnabled(),
            'titleVisible' => $post->isTitleVisible(),
            'metaTitle' => $post->getTranslation('fr')?->getMetaTitle(),
            'gridLayout' => $post->getGridLayout(),
            'bannerLayout' => $post->getBannerLayout(),
        ];

        $this->galleryManager->update($post, $this->galleryInputFactory->fromArray([
            // The two it may say.
            'galleryLayout' => [
                'enabled' => true,
                'items' => [['id' => 'g1', 'mediaId' => 1]],
            ],
            'gallery' => ['fr' => ['items' => ['g1' => ['alt' => 'Une photo', 'caption' => 'Prise ici']]]],
            // And everything it may not, in the shapes the full editor takes.
            'status' => 'draft',
            'postTypeId' => 999_999,
            'commentsEnabled' => false,
            'titleVisible' => false,
            'thumbnailId' => 4,
            'bannerLayout' => ['enabled' => true, 'height' => 'lg'],
            'gridLayout' => ['zones' => [['id' => 'z1', 'type' => 'text']]],
            'translations' => [
                'fr' => [
                    'title' => 'Titre volé',
                    'slug' => 'titre-vole',
                    'metaTitle' => 'SEO volé',
                ],
            ],
        ]));

        $this->entityManager->refresh($post);

        self::assertSame($before['status'], $post->getStatus()->value, 'a gallery write must not publish or unpublish');
        self::assertSame($before['title'], $post->getTranslation('fr')?->getTitle());
        self::assertSame($before['slug'], $post->getTranslation('fr')?->getSlug());
        self::assertSame($before['postTypeId'], $post->getPostType()->getId());
        self::assertSame($before['commentsEnabled'], $post->isCommentsEnabled());
        self::assertSame($before['titleVisible'], $post->isTitleVisible());
        self::assertSame($before['metaTitle'], $post->getTranslation('fr')?->getMetaTitle());
        self::assertSame($before['gridLayout'], $post->getGridLayout());
        self::assertSame($before['bannerLayout'], $post->getBannerLayout());

        // And the part it may change, did change - or the test above would pass
        // against a manager that does nothing at all.
        self::assertTrue($post->getGalleryLayout()['enabled']);
        self::assertSame('Une photo', $post->getTranslation('fr')?->getGallery()['items']['g1']['alt']);
    }

    /**
     * Captions follow the layout in every language, not just the one on screen.
     *
     * The editor shows one locale at a time, so a picture removed while looking at
     * French would otherwise keep its English caption - text nobody can see, on an
     * item that no longer exists, surfacing years later as a stray alt attribute.
     */
    public function testRemovingAPictureClearsItsWordsInEveryLanguage(): void
    {
        $post = $this->publishedPost();

        $this->galleryManager->update($post, $this->galleryInputFactory->fromArray([
            'galleryLayout' => ['enabled' => true, 'items' => [
                ['id' => 'g1', 'mediaId' => 1],
                ['id' => 'g2', 'mediaId' => 2],
            ]],
            'gallery' => [
                'fr' => ['items' => ['g1' => ['alt' => 'Premier'], 'g2' => ['alt' => 'Second']]],
                'en' => ['items' => ['g1' => ['alt' => 'First'], 'g2' => ['alt' => 'Second']]],
            ],
        ]));

        self::assertArrayHasKey('g2', $post->getTranslation('en')?->getGallery()['items'] ?? []);

        // Now the same gallery with the second picture gone, and only French sent -
        // which is what the screen does when the reader was on the French tab.
        $this->galleryManager->update($post, $this->galleryInputFactory->fromArray([
            'galleryLayout' => ['enabled' => true, 'items' => [['id' => 'g1', 'mediaId' => 1]]],
            'gallery' => ['fr' => ['items' => ['g1' => ['alt' => 'Premier']]]],
        ]));

        $this->entityManager->refresh($post);

        self::assertArrayNotHasKey('g2', $post->getTranslation('fr')?->getGallery()['items'] ?? []);
        self::assertArrayNotHasKey('g2', $post->getTranslation('en')?->getGallery()['items'] ?? []);
    }

    /**
     * An empty payload empties the gallery rather than being ignored.
     *
     * "Remove every picture" has to be expressible, and it arrives as an absent
     * key - so treating absent as "leave alone" would make the last deletion
     * impossible.
     */
    public function testAnEmptyPayloadEmptiesTheGallery(): void
    {
        $post = $this->publishedPost();

        $this->galleryManager->update($post, $this->galleryInputFactory->fromArray([
            'galleryLayout' => ['enabled' => true, 'items' => [['id' => 'g1', 'mediaId' => 1]]],
            'gallery' => ['fr' => ['items' => ['g1' => ['alt' => 'Premier']]]],
        ]));

        $this->galleryManager->update($post, $this->galleryInputFactory->fromArray([]));

        $this->entityManager->refresh($post);

        self::assertSame([], $post->getGalleryLayout()['items']);
        self::assertSame([], $post->getTranslation('fr')?->getGallery()['items']);
    }

    /**
     * The endpoint refuses somebody without the gallery grant.
     *
     * The tests above prove the *payload* cannot express more than a gallery. This
     * one proves the door is locked at all - and it was the layer I had left
     * untested while describing it as the security boundary.
     */
    public function testTheEndpointRefusesSomebodyWithoutTheGrant(): void
    {
        $post = $this->publishedPost();
        $outsider = $this->account(['editorial.posts.view']);

        $this->client->loginUser($outsider, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_editorial_post_galleries_update', ['id' => $post->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['galleryLayout' => ['enabled' => true, 'items' => []]], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    /** And admits somebody who holds it, on a post they did not write. */
    public function testTheEndpointAdmitsSomebodyWithTheGrant(): void
    {
        $post = $this->publishedPost();
        $photographer = $this->account(['editorial.posts.view', 'editorial.posts.gallery']);

        $this->client->loginUser($photographer, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_editorial_post_galleries_update', ['id' => $post->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'galleryLayout' => ['enabled' => true, 'items' => [['id' => 'g1', 'mediaId' => 1]]],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }

    /** The list screen is behind the same grant. */
    public function testTheListRefusesSomebodyWithoutTheGrant(): void
    {
        $outsider = $this->account(['editorial.posts.view']);

        $this->client->loginUser($outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_editorial_post_galleries'));

        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<string> $privileges */
    private function account(array $privileges): User
    {
        $user = new User();
        $user->setEmail('gal-'.bin2hex(random_bytes(4)).'@aurora.test');
        $user->setName('Photographe');
        $user->setType(UserTypeEnum::Backend);
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER']);
        $user->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->created[] = [User::class, (int) $user->getId()];

        return $user;
    }

    private function publishedPost(): PostInterface
    {
        $postType = new PostType();
        $postType->setSlug('gallery-scope-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Gallery scope');
        $this->entityManager->persist($postType);
        $this->entityManager->flush();

        $post = $this->postManager->create($this->postInputFactory->fromArray([
            'postTypeId' => $postType->getId(),
            'status' => 'published',
            'commentsEnabled' => true,
            'titleVisible' => true,
            'gridLayout' => ['zones' => [['id' => 'z0', 'type' => 'text']]],
            'translations' => [
                'fr' => ['title' => 'Article original', 'metaTitle' => 'SEO original'],
                'en' => ['title' => 'Original article'],
            ],
        ]));

        $this->entityManager->flush();

        return $post;
    }
}
