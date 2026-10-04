<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;

use function json_decode;
use function json_encode;

/**
 * The preview beside a publication's grid is the page in the site's theme,
 * layout and all; the preview window keeps the grid alone.
 */
final class GridPreviewFrameTest extends IntegrationTestCase
{
    public function testTheSidePreviewIsTheGridInsideThePublicLayout(): void
    {
        $html = $this->preview(['frame' => true]);

        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('data-theme', $html);
        self::assertStringContainsString('data-grid-zone="t1"', $html);
        self::assertStringContainsString('Une phrase', $html);
    }

    public function testThePreviewWindowStillGetsTheGridAlone(): void
    {
        $html = $this->preview([]);

        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('Une phrase', $html);
    }

    /** @param array<string, mixed> $extra */
    private function preview(array $extra): string
    {
        $client = self::createClient();
        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertNotNull($admin);
        $client->loginUser($admin, 'admin');

        $client->request('POST', '/backend/editorial/posts/grid-preview', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], (string) json_encode([
            ...$extra,
            'layout' => ['enabled' => true, 'zones' => [['id' => 't1', 'type' => 'text', 'span' => ['base' => 48, 'md' => 48, 'lg' => 48]]]],
            'content' => ['zones' => ['t1' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Une phrase']]]]]],
            'locale' => 'fr',
        ]));
        self::assertResponseIsSuccessful();

        return (string) json_decode((string) $client->getResponse()->getContent(), true)['html'];
    }
}
