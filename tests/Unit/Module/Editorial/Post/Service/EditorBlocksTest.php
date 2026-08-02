<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use PHPUnit\Framework\TestCase;

/**
 * Blocks have to satisfy the *editor*, which is stricter than the renderer.
 *
 * BlocksRenderer accepts every shape an Editor.js tool has ever emitted, so
 * malformed content renders perfectly well on the public site — and then the
 * backend shows "The block can not be displayed correctly" when an editor
 * opens the post. That is the worst way round: nothing fails until someone
 * tries to edit.
 *
 * A list written as `items: [{content: 'x'}]` is exactly that trap. It is
 * neither the tool's legacy form (`items: ['x']`) nor its current one
 * (`{content, meta, items}`), so it reads and does not edit.
 */
final class EditorBlocksTest extends TestCase
{
    public function testAListCarriesTheKeysTheToolReadsUnconditionally(): void
    {
        $data = EditorBlocks::list(['un', 'deux'])['data'];

        self::assertSame('unordered', $data['style']);
        self::assertCount(2, $data['items']);

        foreach ($data['items'] as $item) {
            self::assertSame(['content', 'meta', 'items'], array_keys($item));
            self::assertSame([], $item['items']);
        }
    }

    /**
     * `meta` has to encode as `{}`. An empty PHP array becomes `[]` in JSON,
     * which is a different type and is what the tool chokes on.
     */
    public function testMetaEncodesAsAnObjectRatherThanAnEmptyArray(): void
    {
        $json = json_encode(EditorBlocks::list(['un']), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"meta":{}', $json);
        self::assertStringNotContainsString('"meta":[]', $json);
    }

    /** The callout tool saves `message`; `text` opens blank. */
    public function testACalloutUsesTheKeyTheToolSaves(): void
    {
        $data = EditorBlocks::callout('Attention', 'warning')['data'];

        self::assertSame(['type', 'title', 'message'], array_keys($data));
        self::assertSame('Attention', $data['message']);
        self::assertSame('warning', $data['type']);
    }

    public function testAnImageNestsItsUrlUnderFile(): void
    {
        $data = EditorBlocks::image('/uploads/a.jpg', 'Une légende')['data'];

        self::assertSame('/uploads/a.jpg', $data['file']['url']);
        self::assertSame('Une légende', $data['caption']);
    }
}
