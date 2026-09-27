<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Entity\Post;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which colour a publication's card takes on hover wherever it is listed.
 */
final class CardHighlightColorTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string, ?string}> */
    public static function cases(): iterable
    {
        yield 'nothing set follows the listing page' => [null, null, null, null];
        yield 'the accent announces the page' => [null, null, '#bd4a55', '#bd4a55'];
        yield 'a custom hover colour wins over the accent' => ['custom', '#cd8f31', '#bd4a55', '#cd8f31'];
        yield 'custom without a colour falls back to the accent' => ['custom', null, '#bd4a55', '#bd4a55'];
        yield 'accent mode keeps the accent' => ['accent', '#cd8f31', '#bd4a55', '#bd4a55'];
        yield 'neutral asks for no colour anywhere' => ['neutral', '#cd8f31', '#bd4a55', null];
        yield 'a value that is not a hex colour never reaches the style attribute' => [null, null, 'red;background:url(x)', null];
    }

    #[DataProvider('cases')]
    public function testTheCardColour(?string $highlight, ?string $highlightColor, ?string $accent, ?string $expected): void
    {
        $post = new Post()
            ->setHighlight($highlight)
            ->setHighlightColor($highlightColor)
            ->setAccentColor($accent);

        self::assertSame($expected, $post->getCardHighlightColor());
    }
}
