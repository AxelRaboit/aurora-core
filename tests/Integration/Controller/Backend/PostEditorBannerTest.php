<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller\Backend;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Covers the wiring the editor page depends on, which nothing else does:
 * the banner has to reach the Vue component as a complete shape, and the
 * preview endpoint has to answer with markup rather than a redirect.
 *
 * Written because the panel itself sits behind a login and cannot be opened
 * from a terminal - this is what verifies it is plugged in at all.
 */
final class PostEditorBannerTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testTheEditorPageShipsACompleteBannerToTheComponent(): void
    {
        $this->client->request('GET', '/backend/editorial/posts/new');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $html = (string) $this->client->getResponse()->getContent();

        // The component is mounted with its props JSON-encoded into the markup,
        // so finding the route there is what proves the panel can call it.
        // Matched on the last segment alone: the props are JSON inside an HTML
        // attribute, so the path arrives with its slashes escaped and its
        // quotes entity-encoded, and the literal route never appears.
        self::assertStringContainsString('bannerPreviewPath', $html);
        self::assertStringContainsString('banner-preview', $html);
    }

    /**
     * The focal picker reads `thumbnailFocalPosition` to show what clearing an
     * override falls back to. It bound to a key the serialiser never emitted,
     * so the marker always claimed the centre - silently, because a missing
     * key in a prop reads as a default rather than as an error.
     */
    public function testTheEditorReceivesEveryThumbnailControlItBindsTo(): void
    {
        $this->client->request('GET', sprintf('/backend/editorial/posts/%d/edit', $this->createPost()));

        $html = (string) $this->client->getResponse()->getContent();

        foreach ([
            'thumbnailId',
            'thumbnailUrl',
            'thumbnailFit',
            'thumbnailFocalX',
            'thumbnailFocalY',
            'thumbnailFocalPosition',
        ] as $key) {
            self::assertStringContainsString($key, $html, $key.' never reaches the editor');
        }
    }

    /** The suite seeds no publications, so the edit page needs one of its own. */
    private function createPost(): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $postType = new PostType();
        $postType->setSlug('editor-props-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Editor props');

        $post = new Post();
        $post->setPostType($postType);

        $entityManager->persist($postType);
        $entityManager->persist($post);
        $entityManager->flush();

        return (int) $post->getId();
    }

    public function testThePreviewEndpointRendersAnUnsavedBanner(): void
    {
        $this->client->request(
            'POST',
            '/backend/editorial/posts/banner-preview',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'layout' => [
                    'enabled' => true,
                    'background' => ['type' => 'solid', 'color' => '#123456'],
                    'items' => [['id' => 'a1', 'type' => 'text']],
                ],
                'texts' => ['items' => ['a1' => ['title' => 'Titre de test']]],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['success']);
        self::assertStringContainsString('Titre de test', $payload['html']);
        self::assertStringContainsString('#123456', $payload['html']);
    }

    /**
     * The preview normalises like any other write. A colour that is not a hex
     * must not reach the markup, or the endpoint would be a way around the
     * whitelist every saved banner goes through.
     */
    public function testThePreviewRefusesAColourTheNormaliserWouldReject(): void
    {
        $this->client->request(
            'POST',
            '/backend/editorial/posts/banner-preview',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'layout' => [
                    'enabled' => true,
                    'items' => [[
                        'id' => 'a1',
                        'type' => 'text',
                        'titleColor' => 'red; background: url(javascript:alert(1))',
                    ]],
                ],
                'texts' => ['items' => ['a1' => ['title' => 'Titre']]],
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('javascript:', $payload['html']);
    }
}
