<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Share;

use Aurora\Module\Editorial\Post\Share\ShareLinksNormalizer;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function is_string;
use function preg_match;
use function preg_match_all;
use function sprintf;

/**
 * The share block's vocabulary is written twice: `ShareLinksNormalizer` holds
 * it at the write boundary, `shareLinks.js` offers it in the editor and draws
 * it on the page. A type added on one side only would be offered and then
 * dropped on save, or accepted and never offered - silently either way. The
 * convention for a mirrored contract asks for a test, not a comment.
 */
final class ShareLinksContractMirrorTest extends TestCase
{
    public function testTheEditorOffersExactlyTheTypesTheServerKeeps(): void
    {
        self::assertSame(1, preg_match('/export const SHARE_TYPES = \[(.*?)\];/s', $this->source(), $matches), 'SHARE_TYPES is not exported from shareLinks.js any more');
        preg_match_all('/"([^"]+)"/', $matches[1], $types);

        self::assertSame(ShareLinksNormalizer::TYPES, $types[1]);
    }

    public function testTheLinkCapIsTheSameOnBothSides(): void
    {
        self::assertSame(1, preg_match('/export const MAX_SHARE_LINKS = (\d+);/', $this->source(), $matches), 'MAX_SHARE_LINKS is not exported from shareLinks.js any more');

        self::assertSame(ShareLinksNormalizer::MAX_LINKS, (int) $matches[1]);
    }

    public function testEveryDefaultLinkIsATypeTheServerKeeps(): void
    {
        self::assertSame(1, preg_match('/export const DEFAULT_SHARE_LINKS = \[(.*?)\];/s', $this->source(), $matches));
        preg_match_all('/type: "([^"]+)"/', $matches[1], $types);

        self::assertNotEmpty($types[1]);
        foreach ($types[1] as $type) {
            self::assertContains($type, ShareLinksNormalizer::TYPES);
        }
    }

    private function source(): string
    {
        $path = dirname(__DIR__, 6).'/src/Module/Editorial/assets/frontend/shareLinks.js';
        $source = file_get_contents($path);

        self::assertTrue(is_string($source), sprintf('shareLinks.js not found at %s', $path));

        return $source;
    }
}
