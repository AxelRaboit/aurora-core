<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Deliverable\Slides;

use Aurora\Core\Content\VideoEmbedResolver;
use Aurora\Module\Studio\Deliverable\Slides\Entity\Slide;
use Aurora\Module\Studio\Deliverable\Slides\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckStyleNormalizer;
use Aurora\Module\Studio\Deliverable\Slides\Service\FreeSlideNormalizer;
use Aurora\Module\Studio\Deliverable\Slides\Service\FreeTextSanitizer;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_map;
use function range;

/**
 * What a free slide keeps out of what the canvas posts.
 *
 * The canvas is drawn on a public share link from JSON a page sent, so every
 * key an element may carry is declared and every value bounded. These are the
 * tests that the declaration is consulted, and that a value outside it lands
 * on the nearest edge rather than on the wall.
 */
final class FreeSlideNormalizerTest extends TestCase
{
    public function testAFreeSlideKeepsItsElementsItsPaintAndItsFilm(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Free);

        $this->manager()->writeContent($slide, [
            'elements' => [['id' => 'title', 'type' => 'text', 'x' => 10, 'y' => 12, 'w' => 60, 'h' => 20, 'html' => 'Bilan']],
            'fill' => ['type' => 'solid', 'color' => '#112233'],
            'bgVideoId' => 12,
            'bullets' => ['un slot que ce gabarit ne porte pas'],
        ]);

        $content = $slide->getContent();

        self::assertSame(['fill', 'bgVideoId', 'elements'], array_keys($content));
        self::assertSame('title', $content['elements'][0]['id']);
        self::assertSame(['type' => 'solid', 'color' => '#112233'], $content['fill']);
    }

    public function testAnElementOfAnUnknownTypeIsDropped(): void
    {
        $elements = $this->normalizer()->elements([
            ['type' => 'script', 'x' => 0],
            ['type' => 'shape', 'shape' => 'star'],
            'not an element',
        ]);

        self::assertCount(1, $elements);
        self::assertSame('star', $elements[0]['shape']);
    }

    /** A handle dragged past the edge lands on the edge, not off the wall. */
    public function testPositionsAndSizesAreClamped(): void
    {
        [$element] = $this->normalizer()->elements([
            ['type' => 'shape', 'x' => -900, 'y' => 'haut', 'w' => 0, 'h' => 9000, 'rotate' => 450, 'opacity' => 3],
        ]);

        self::assertSame(-100.0, $element['x']);
        self::assertSame(10.0, $element['y']);
        self::assertSame(0.2, $element['w']);
        self::assertSame(400.0, $element['h']);
        self::assertSame(90.0, $element['rotate']);
        self::assertArrayNotHasKey('opacity', $element);
    }

    public function testTwoElementsNeverShareAnId(): void
    {
        $elements = $this->normalizer()->elements([
            ['id' => 'same', 'type' => 'shape'],
            ['id' => 'same', 'type' => 'shape'],
            ['id' => '<b>', 'type' => 'shape'],
        ]);

        $ids = array_map(static fn (array $element): string => $element['id'], $elements);

        self::assertSame('same', $ids[0]);
        self::assertNotSame('same', $ids[1]);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $ids[2]);
        self::assertCount(3, array_unique($ids));
    }

    public function testASlideHoldsAtMostTwoHundredElements(): void
    {
        $elements = $this->normalizer()->elements(array_fill(0, 250, ['type' => 'shape']));

        self::assertCount(FreeSlideNormalizer::MAX_ELEMENTS, $elements);
    }

    /** A text box's words land in `v-html` on a public page. */
    public function testTextIsSanitised(): void
    {
        [$element] = $this->normalizer()->elements([[
            'type' => 'text',
            'html' => '<b onclick="alert(1)">Gras</b><script>alert(1)</script><a href="javascript:x">lien</a>'
                .'<span style="color: #ff0000; position: fixed">rouge</span>',
            'font' => 'playfair-display',
            'size' => 9999,
            'weight' => 650,
            'align' => 'diagonal',
        ]]);

        self::assertSame('<b>Gras</b>lien<span style="color: #ff0000">rouge</span>', $element['html']);
        self::assertSame('playfair-display', $element['font']);
        self::assertSame(600.0, $element['size']);
        self::assertSame(700, $element['weight']);
        self::assertSame('left', $element['align']);
        self::assertTrue($element['autofit']);
    }

    public function testAFontIsARoleACatalogueKeyOrAnUploadedFile(): void
    {
        $fonts = array_map(
            fn (string $font): ?string => $this->normalizer()->elements([['type' => 'text', 'font' => $font]])[0]['font'] ?? null,
            ['heading', 'inter', 'upload-42', 'Comic Sans MS', 'url(x)'],
        );

        self::assertSame(['heading', 'inter', 'upload-42', null, null], $fonts);
    }

    public function testAPaintIsAColourOrAGradientOfTwoToSix(): void
    {
        $normalizer = $this->normalizer();

        self::assertSame(['type' => 'solid', 'color' => 'accent'], $normalizer->paint(['type' => 'solid', 'color' => 'Accent']));
        self::assertNull($normalizer->paint(['type' => 'solid', 'color' => 'red']));
        self::assertNull($normalizer->paint(['type' => 'pattern']));

        $gradient = $normalizer->paint([
            'type' => 'linear',
            'angle' => 400,
            'stops' => array_map(static fn (int $at): array => ['color' => '#00000080', 'at' => $at * 10], range(0, 9)),
        ]);

        self::assertSame('linear', $gradient['type']);
        self::assertSame(360.0, $gradient['angle']);
        self::assertCount(6, $gradient['stops']);

        // One stop left after cleaning is a flat colour.
        self::assertSame(
            ['type' => 'solid', 'color' => '#abcdef'],
            $normalizer->paint(['type' => 'radial', 'stops' => [['color' => '#abcdef', 'at' => 0], ['color' => 'nope', 'at' => 100]]]),
        );
    }

    /** A film set to play by itself would not start with its sound on. */
    public function testAFilmThatPlaysByItselfIsMuted(): void
    {
        [$element] = $this->normalizer()->elements([
            ['type' => 'video', 'mediaId' => 9, 'autoplay' => true, 'muted' => false, 'mask' => 'circle', 'fit' => 'stretch'],
        ]);

        self::assertTrue($element['autoplay']);
        self::assertTrue($element['muted']);
        self::assertSame('circle', $element['mask']);
        self::assertSame('cover', $element['fit']);
        self::assertSame(9, $element['mediaId']);
    }

    /** The resolved address goes in an iframe, so only known providers stay. */
    public function testAnEmbedKeepsOnlyAnAddressAKnownProviderServes(): void
    {
        [$youtube, $other] = $this->normalizer()->elements([
            ['type' => 'embed', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['type' => 'embed', 'url' => 'https://evil.example.com/player'],
        ]);

        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $youtube['url']);
        self::assertArrayNotHasKey('url', $other);
    }

    public function testAPictureKeepsOnlyTheAdjustmentsThatChangeIt(): void
    {
        [$element] = $this->normalizer()->elements([[
            'type' => 'image',
            'mediaId' => 3,
            'zoom' => 2,
            'focus' => '30% 70%',
            'filters' => ['brightness' => 100, 'contrast' => 140, 'blur' => 400, 'invert' => 100],
        ]]);

        self::assertSame(['contrast' => 140, 'blur' => 40], $element['filters']);
        self::assertSame(2.0, $element['zoom']);
        self::assertSame('30% 70%', $element['focus']);
    }

    public function testALinkUsesASchemeThatCannotRunAnything(): void
    {
        $links = array_map(
            fn (string $link): ?string => $this->normalizer()->elements([['type' => 'shape', 'link' => $link]])[0]['link'] ?? null,
            ['https://example.com', 'mailto:contact@example.com', 'javascript:alert(1)', '/suite'],
        );

        self::assertSame(['https://example.com', 'mailto:contact@example.com', null, null], $links);
    }

    public function testAnEntranceCarriesItsTimingAndARevealIsAPress(): void
    {
        [$element, $none] = $this->normalizer()->elements([
            ['type' => 'shape', 'enter' => 'rise', 'duration' => 20, 'delay' => 300, 'reveal' => 2],
            ['type' => 'shape', 'enter' => 'none', 'reveal' => 0],
        ]);

        self::assertSame('rise', $element['enter']);
        self::assertSame(100, $element['duration']);
        self::assertSame(300, $element['delay']);
        self::assertSame(2, $element['reveal']);
        self::assertArrayNotHasKey('enter', $none);
        self::assertArrayNotHasKey('reveal', $none);
    }

    public function testAStrokeNeedsAColourAndAWidth(): void
    {
        [$stroked, $bare] = $this->normalizer()->elements([
            ['type' => 'shape', 'stroke' => ['color' => '#000000', 'width' => 4, 'style' => 'wavy']],
            ['type' => 'shape', 'stroke' => ['color' => '#000000', 'width' => 0]],
        ]);

        self::assertSame(['color' => '#000000', 'width' => 4.0, 'style' => 'solid'], $stroked['stroke']);
        self::assertArrayNotHasKey('stroke', $bare);
    }

    private function normalizer(): FreeSlideNormalizer
    {
        return new FreeSlideNormalizer(new FreeTextSanitizer(), new VideoEmbedResolver());
    }

    private function manager(): SlidesManager
    {
        return new SlidesManager(
            $this->createStub(EntityManagerInterface::class),
            new DeckStyleNormalizer(),
            $this->normalizer(),
        );
    }
}
