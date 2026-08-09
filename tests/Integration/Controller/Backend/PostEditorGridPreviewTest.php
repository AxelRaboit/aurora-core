<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller\Backend;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The grid preview renders through the same Twig the public page uses, which
 * is the whole reason it is a server round-trip rather than a Vue
 * reimplementation: a preview that can disagree with what gets published is
 * worse than none, because it is believed.
 *
 * Covered here because the panel sits behind a login and cannot be opened from
 * a terminal — this is what verifies it is plugged in at all.
 */
final class PostEditorGridPreviewTest extends IntegrationTestCase
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

    public function testItRendersAnUnsavedGrid(): void
    {
        $payload = $this->preview([
            'layout' => [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'text', 'span' => ['base' => 48, 'lg' => 24]]],
            ],
            'content' => ['zones' => ['z1' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Texte de test']],
            ]]]],
            'locale' => 'fr',
        ]);

        self::assertTrue($payload['success']);
        self::assertStringContainsString('aurora-grid', $payload['html']);
        self::assertStringContainsString('Texte de test', $payload['html']);
        self::assertStringContainsString('--span-lg: 24;', $payload['html']);
    }

    /**
     * The panel asks for a preview while the grid is still switched off or
     * half-composed. Answering "nothing" there would look like a bug rather
     * than a state, which is why it builds for the editor rather than for the
     * page.
     */
    public function testADisabledGridStillRendersItsFrame(): void
    {
        $payload = $this->preview(['layout' => ['enabled' => false, 'zones' => []], 'locale' => 'fr']);

        self::assertTrue($payload['success']);
        self::assertStringContainsString('aurora-grid', $payload['html']);
    }

    /** The preview normalises like any other write. */
    public function testItRefusesAVideoAddressTheNormaliserWouldReject(): void
    {
        $payload = $this->preview([
            'layout' => ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'video']]],
            'content' => ['zones' => ['z1' => ['url' => 'javascript:alert(1)']]],
            'locale' => 'fr',
        ]);

        self::assertStringNotContainsString('javascript:', $payload['html']);
    }

    public function testTextGoesThroughTheBlockSanitiser(): void
    {
        $payload = $this->preview([
            'layout' => ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'text']]],
            'content' => ['zones' => ['z1' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => '<a href="javascript:alert(1)">clic</a>']],
            ]]]],
            'locale' => 'fr',
        ]);

        self::assertStringNotContainsString('javascript:', $payload['html']);
    }

    /**
     * The locale reaches a route generator. One the site does not speak would
     * throw there rather than render, so it is whitelisted against what is
     * actually configured.
     */
    public function testALocaleTheSiteDoesNotSpeakFallsBackInsteadOfFailing(): void
    {
        $payload = $this->preview([
            'layout' => ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'text']]],
            'content' => [],
            'locale' => '../../etc/passwd',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($payload['success']);
    }

    public function testAnEmptyBodyIsAnswered(): void
    {
        $this->client->request(
            'POST',
            '/backend/editorial/posts/grid-preview',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function preview(array $payload): array
    {
        $this->client->request(
            'POST',
            '/backend/editorial/posts/grid-preview',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
