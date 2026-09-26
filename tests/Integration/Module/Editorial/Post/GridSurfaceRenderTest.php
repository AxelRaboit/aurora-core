<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * What a zone sits on, from the stored value to the markup.
 *
 * The surface is the one grid setting with no field of its own to show for it:
 * it adds no words, only a background, so nothing but the rendered page says
 * whether it worked. A view-builder test would assert that a string travelled;
 * this asserts that the page changed.
 */
final class GridSurfaceRenderTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'Frontend/themes/default/editorial/post/_grid_zone.html.twig';

    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testAZoneWithNoSurfaceIsWrappedInNothing(): void
    {
        $html = $this->render($this->zone());

        self::assertStringNotContainsString('bg-surface-2', $html);
        self::assertStringNotContainsString('w-screen', $html);
    }

    public function testACardDrawsItsOwnBox(): void
    {
        $html = $this->render($this->zone(['surface' => 'card']));

        self::assertStringContainsString('rounded-xl', $html);
        self::assertStringContainsString('border-line', $html);
    }

    public function testATintedZoneReadsAsOneSection(): void
    {
        self::assertStringContainsString('bg-surface-2', $this->render($this->zone(['surface' => 'soft'])));
    }

    /**
     * The band spans the viewport; the words do not. A paragraph set at the
     * width of a screen is unreadable, so the content goes back into the box
     * `<main>` uses - which is also what keeps a full-width section lined up
     * with the title above it.
     */
    public function testAFullWidthBandHoldsItsWordsAtThePagesWidth(): void
    {
        $html = $this->render($this->zone(['surface' => 'accent', 'fullBleed' => true]));

        self::assertStringContainsString('w-screen', $html);
        self::assertStringContainsString('max-w-7xl', $html);
        // A band with rounded ends reads as a card that has overflowed.
        self::assertStringNotContainsString('rounded-xl', $html);
    }

    /**
     * A call to action is a title, a line and a button in one band, which in
     * this grid is a stack - so a stack that cannot have a background is a
     * call to action that cannot be built.
     */
    public function testAStackSitsOnItsSurfaceToo(): void
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [[
                    'id' => 's1',
                    'type' => 'stack',
                    'surface' => 'accent',
                    'fullBleed' => true,
                    'children' => [['id' => 'c1', 'type' => 'text']],
                ]],
            ],
            ['zones' => ['c1' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Parlons-en.']]]]]],
            'fr',
        );

        self::assertNotNull($grid);

        $html = $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid.html.twig',
            ['grid' => $grid, 'locale' => 'fr'],
        );

        self::assertStringContainsString('w-screen', $html);
        self::assertStringContainsString('bg-accent-500/10', $html);
        self::assertStringContainsString('Parlons-en.', $html);
    }

    /**
     * A picture alone on a card is framed by it, so the padding goes: a
     * photograph inset by two centimetres of background reads as a picture
     * that failed to fill its box.
     */
    public function testAPictureAloneFillsItsCard(): void
    {
        $html = $this->render($this->pictureZone(['surface' => 'card']));

        self::assertStringContainsString('overflow-hidden', $html);
        self::assertStringNotContainsString('p-6', $html);
        // The card clips the corners, so the picture must not round its own -
        // two radii on one corner leave a sliver of card showing through.
        self::assertStringNotContainsString('rounded-lg', $html);
    }

    /** With words under it the padding stays: a caption must not touch an edge. */
    public function testAPictureWithACaptionKeepsItsPadding(): void
    {
        $html = $this->render($this->pictureZone(['surface' => 'card'], 'Une légende.'));

        self::assertStringContainsString('p-6', $html);
        self::assertStringContainsString('Une légende.', $html);
    }

    // ── The `custom` surface's own background ──────────────────────────────

    public function testACustomSolidSurfaceDrawsItsOwnColour(): void
    {
        $html = $this->render($this->zone([
            'surface' => 'custom',
            'background' => ['type' => 'solid', 'color' => '#123456'],
        ]));

        self::assertStringContainsString('background-color: #123456;', $html);
    }

    public function testACustomGradientSurfaceDrawsBothStopsAndTheAngle(): void
    {
        $html = $this->render($this->zone([
            'surface' => 'custom',
            'background' => [
                'type' => 'gradient',
                'gradientFrom' => '#111111',
                'gradientTo' => '#222222',
                'gradientAngle' => 45,
            ],
        ]));

        self::assertStringContainsString('linear-gradient(45deg, #111111, #222222)', $html);
    }

    /** No colour, no gradient, no picture: the same "nothing chosen" a fresh zone starts with. */
    public function testACustomSurfaceWithNothingSetDrawsNoStyleAtAll(): void
    {
        $html = $this->render($this->zone(['surface' => 'custom']));

        self::assertStringNotContainsString('style=""', $html);
        self::assertStringNotContainsString('background-', $html);
    }

    public function testACustomSurfacesPictureSitsBehindTheContentWithItsOverlay(): void
    {
        $document = new Document();
        $document->setTitle('Fond');
        $document->setMimeType('image/png');
        $document->setFilePath('ged/2026/09/fond.png');

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->created[] = (int) $document->getId();

        $html = $this->render($this->zone([
            'surface' => 'custom',
            'background' => ['mediaId' => $document->getId(), 'overlay' => 40],
        ]));

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('-z-10', $html);
        self::assertStringContainsString('opacity: 0.4;', $html);
    }

    // ── A forced light/dark scheme, independent of the surface ─────────────

    public function testAForcedDarkZoneCarriesItsWholeTokenSet(): void
    {
        $html = $this->render($this->zone(['contrast' => 'dark']));

        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $html);
        self::assertStringContainsString('--color-border: rgb(55 65 81);', $html);
    }

    /** No surface at all is still a valid case: the wrapper appears just to carry the style. */
    public function testAForcedSchemeDrawsAWrapperEvenWithNoSurface(): void
    {
        $html = $this->render($this->zone(['surface' => 'none', 'contrast' => 'light']));

        self::assertStringContainsString('--th-primary: rgb(17 24 39);', $html);
    }

    public function testTheDefaultContrastPosesNoStyleAtAll(): void
    {
        $html = $this->render($this->zone());

        self::assertStringNotContainsString('--th-primary:', $html);
    }

    /**
     * A film the library holds is played by the browser, not by a provider.
     *
     * The zone offered an address and nothing else, so a client's showreel had
     * to be published on YouTube before it could appear on their own site.
     */
    public function testAVideoZonePlaysAFileFromTheLibrary(): void
    {
        $html = $this->render($this->videoZone('video/mp4'));

        self::assertStringContainsString('<video', $html);
        self::assertStringContainsString('reel.mp4', $html);
        self::assertStringContainsString('preload="none"', $html);
        // No iframe: a hosted film has no provider to embed.
        self::assertStringNotContainsString('<iframe', $html);
    }

    /**
     * The mime is checked at render, not trusted from the layout: a document
     * whose file is replaced after the zone was configured would otherwise
     * point a player at a PDF.
     */
    public function testAVideoZoneRefusesADocumentThatIsNotAFilm(): void
    {
        self::assertStringNotContainsString('<video', $this->render($this->videoZone('application/pdf')));
    }

    private function videoZone(string $mimeType): array
    {
        $document = new Document();
        $document->setTitle('Réel');
        $document->setMimeType($mimeType);
        $document->setFilePath('ged/2026/09/reel.mp4');

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->created[] = (int) $document->getId();

        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'v1', 'type' => 'video', 'mediaId' => $document->getId()]],
            ],
            ['zones' => ['v1' => []]],
            'fr',
        );

        self::assertNotNull($grid);

        return $grid['zones'][0];
    }

    /**
     * The three alignments a button offers, each landing where it says.
     *
     * The template used to test for `end`, a word the normaliser never
     * writes: it keeps `center`, `left` and `right`. So a button aligned
     * right came out on the left, and the only setting that worked was the
     * one nobody had to choose.
     */
    public function testAButtonLandsOnTheSideItAsksFor(): void
    {
        self::assertStringContainsString('justify-center', $this->render($this->buttonZone('center')));
        self::assertStringContainsString('justify-start', $this->render($this->buttonZone('left')));
        self::assertStringContainsString('justify-end', $this->render($this->buttonZone('right')));
    }

    // ── The separator's shapes ──────────────────────────────────────────────

    public function testALineSeparatorDrawsAnHr(): void
    {
        self::assertStringContainsString('<hr', $this->render($this->separatorZone('line')));
    }

    public function testASpaceSeparatorDrawsNothing(): void
    {
        $html = $this->render($this->separatorZone('space'));

        self::assertStringNotContainsString('<hr', $html);
        self::assertStringNotContainsString('<svg', $html);
    }

    public function testAWaveSeparatorDrawsACurvedPath(): void
    {
        self::assertStringContainsString('<path', $this->render($this->separatorZone('wave')));
    }

    public function testADiagonalSeparatorDrawsAStraightLine(): void
    {
        self::assertStringContainsString('<line', $this->render($this->separatorZone('diagonal')));
    }

    public function testABevelSeparatorDrawsAPolyline(): void
    {
        self::assertStringContainsString('<polyline', $this->render($this->separatorZone('bevel')));
    }

    private function separatorZone(string $style): array
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [['id' => 's1', 'type' => 'separator', 'separatorStyle' => $style]]],
            ['zones' => ['s1' => []]],
            'fr',
        );

        self::assertNotNull($grid);

        return $grid['zones'][0];
    }

    private function buttonZone(string $align): array
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'b1', 'type' => 'button', 'align' => $align]],
            ],
            ['zones' => ['b1' => ['label' => 'En savoir plus', 'url' => 'https://example.test']]],
            'fr',
        );

        self::assertNotNull($grid);

        return $grid['zones'][0];
    }

    /** @param array<string, mixed> $overrides */
    private function pictureZone(array $overrides = [], string $caption = ''): array
    {
        $document = new Document();
        $document->setTitle('Média');
        $document->setMimeType('image/png');
        $document->setFilePath('ged/2026/09/photo.png');

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->created[] = (int) $document->getId();

        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'media', 'mediaId' => $document->getId(), ...$overrides]],
            ],
            ['zones' => ['z1' => ['alt' => 'Une image', 'caption' => $caption]]],
            'fr',
        );

        self::assertNotNull($grid);

        return $grid['zones'][0];
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $document = $this->entityManager->find(Document::class, $id);

            if (null !== $document) {
                $this->entityManager->remove($document);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function zone(array $overrides = []): array
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'text', ...$overrides]],
            ],
            ['zones' => ['z1' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Une phrase.']]]]]],
            'fr',
        );

        self::assertNotNull($grid);

        return $grid['zones'][0];
    }

    /** @param array<string, mixed> $zone */
    private function render(array $zone): string
    {
        return $this->twig->render(self::TEMPLATE, ['zone' => $zone, 'locale' => 'fr']);
    }
}
