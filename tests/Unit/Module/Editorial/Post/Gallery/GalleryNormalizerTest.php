<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Gallery;

use Aurora\Module\Editorial\Post\Gallery\GalleryNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The gallery's write boundary.
 *
 * Everything a gallery can be is decided here, so this is where the decisions
 * are pinned. The normalizer's job is that a gallery saved by an older editor, a
 * fixture, or a client project reads back with the same keys as one saved today -
 * which means the interesting cases are all malformed input, not well-formed.
 */
final class GalleryNormalizerTest extends TestCase
{
    private GalleryNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GalleryNormalizer();
    }

    public function testGarbageNormalisesToAGalleryThatIsOff(): void
    {
        foreach ([null, 'nonsense', 42, []] as $raw) {
            $layout = $this->normalizer->normalizeLayout($raw);

            self::assertFalse($layout['enabled']);
            self::assertSame(GalleryNormalizer::LAYOUT_GRID, $layout['layout']);
            self::assertSame(GalleryNormalizer::DEFAULT_COLUMNS, $layout['columns']);
            self::assertSame(GalleryNormalizer::RATIO_NATURAL, $layout['ratio']);
            self::assertSame([], $layout['items']);
        }
    }

    /**
     * A value outside the list falls to the default rather than through. A
     * column count of 97 is not a gallery nobody asked for, it is a page that
     * cannot lay out.
     */
    public function testAnUnknownSettingFallsToItsDefault(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'layout' => 'carousel',
            'columns' => 97,
            'ratio' => 'fill',
        ]);

        self::assertSame(GalleryNormalizer::LAYOUT_GRID, $layout['layout']);
        self::assertSame(GalleryNormalizer::DEFAULT_COLUMNS, $layout['columns']);
        // `fill` exists in the grid's media zone and deliberately not here: it
        // means "take the height the stack has left", and there is no stack.
        self::assertSame(GalleryNormalizer::RATIO_NATURAL, $layout['ratio']);
    }

    /**
     * An item is its picture. Unlike a grid zone, which exists before it is
     * filled and is a gap worth keeping, an item with no media is nothing.
     */
    public function testAnItemWithoutAPictureIsDropped(): void
    {
        $layout = $this->normalizer->normalizeLayout(['items' => [
            ['id' => 'a', 'mediaId' => 7],
            ['id' => 'b'],
            ['id' => 'c', 'mediaId' => null],
            ['mediaId' => 9],
            'nonsense',
        ]]);

        self::assertSame([['id' => 'a', 'mediaId' => 7]], $layout['items']);
    }

    /**
     * The same picture twice is a mistake every time, and one the author cannot
     * see on a long page. The first placement wins, so removing the duplicate
     * does not move the gallery.
     */
    public function testTheSamePictureIsKeptOnce(): void
    {
        $layout = $this->normalizer->normalizeLayout(['items' => [
            ['id' => 'a', 'mediaId' => 4],
            ['id' => 'b', 'mediaId' => 4],
            ['id' => 'c', 'mediaId' => 5],
        ]]);

        self::assertSame(
            [['id' => 'a', 'mediaId' => 4], ['id' => 'c', 'mediaId' => 5]],
            $layout['items'],
        );
    }

    public function testTheItemCountIsCapped(): void
    {
        $items = [];
        for ($i = 0; $i < GalleryNormalizer::MAX_ITEMS + 20; ++$i) {
            $items[] = ['id' => 'i'.$i, 'mediaId' => $i + 1];
        }

        self::assertCount(
            GalleryNormalizer::MAX_ITEMS,
            $this->normalizer->normalizeLayout(['items' => $items])['items'],
        );
    }

    /**
     * The content half is keyed by the layout, not by what the client sent - an
     * item the author removed must not keep its caption in five languages.
     */
    public function testContentFollowsTheLayoutAndNotTheRequest(): void
    {
        $layout = $this->normalizer->normalizeLayout(['items' => [
            ['id' => 'kept', 'mediaId' => 1],
        ]]);

        $content = $this->normalizer->normalizeContent([
            'items' => [
                'kept' => ['alt' => '  Un mur  ', 'caption' => 'Rue de la Paix'],
                'gone' => ['alt' => 'orphan', 'caption' => 'orphan'],
            ],
        ], $layout);

        self::assertSame(['kept'], array_keys($content['items']));
        self::assertSame('Un mur', $content['items']['kept']['alt']);
        self::assertSame('Rue de la Paix', $content['items']['kept']['caption']);
    }

    /**
     * An item present in the layout with nothing written for it still gets its
     * keys, so a template reads `alt` rather than testing for it.
     */
    public function testAnItemWithNothingWrittenStillHasItsKeys(): void
    {
        $layout = $this->normalizer->normalizeLayout(['items' => [['id' => 'a', 'mediaId' => 1]]]);

        self::assertSame(['alt' => '', 'caption' => ''], $this->normalizer->normalizeContent(null, $layout)['items']['a']);
    }

    /**
     * No gallery at all is a legitimate argument, not a crash: most posts have
     * none, and every one of them goes through this call.
     */
    public function testAnEmptyLayoutIsNotAnError(): void
    {
        self::assertSame(['items' => []], $this->normalizer->normalizeContent(['items' => ['x' => []]], []));
    }

    /**
     * What it writes, it has to be able to read.
     *
     * It could not: it read `items` and returned the bare map, so the column
     * stored a shape this method did not recognise. The next pass found no `items`
     * key, emptied every caption, and the save after that wrote the blanks - an
     * author's alt text disappeared on reload and was gone for good. Found while
     * building the gallery-only screen, whose test asserted a caption survived and
     * got an empty string.
     *
     * {@see GridNormalizer::normalizeContent()} was symmetric all along, which is
     * what made the difference visible.
     */
    public function testWhatItWritesItCanReadBack(): void
    {
        $layout = $this->normalizer->normalizeLayout(['items' => [['id' => 'g1', 'mediaId' => 1]]]);

        $once = $this->normalizer->normalizeContent(
            ['items' => ['g1' => ['alt' => 'Un mur', 'caption' => 'Rue de la Paix']]],
            $layout,
        );

        $twice = $this->normalizer->normalizeContent($once, $layout);

        self::assertSame($once, $twice, 'a second pass must not empty what the first stored');
        self::assertSame('Un mur', $twice['items']['g1']['alt']);
    }

    /**
     * The bare map still reads, which is what recovers the rows written while it
     * was asymmetric.
     *
     * Not ambiguous: item ids are strings and `items` is not one of them, so a
     * payload with no `items` key is the map itself.
     */
    public function testTheShapeWrittenWhileItWasBrokenStillReads(): void
    {
        $layout = $this->normalizer->normalizeLayout(['items' => [['id' => 'g1', 'mediaId' => 1]]]);

        $content = $this->normalizer->normalizeContent(
            ['g1' => ['alt' => 'Écrit avant le correctif', 'caption' => '']],
            $layout,
        );

        self::assertSame('Écrit avant le correctif', $content['items']['g1']['alt']);
    }
}
