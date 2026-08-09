<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;

/**
 * The join between the grid's two halves, and the place that decides what a
 * template actually receives.
 *
 * Written as an integration test because half of what it does is resolve ids
 * against the database — a unit test with mocked repositories would assert
 * that the mocks were called, which is not the thing that breaks.
 */
final class GridViewBuilderTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
    }

    public function testAZoneCarriesBothItsArrangementAndItsContent(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'text', 'span' => ['base' => 48, 'lg' => 24]]],
            ],
            ['zones' => ['z1' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Bonjour']],
            ]]]],
            'fr',
        );

        $zone = $grid['zones'][0];

        self::assertSame('text', $zone['type']);
        self::assertStringContainsString('Bonjour', (string) $zone['html']);
        self::assertStringContainsString('--span-lg: 24;', $zone['spanStyle']);
    }

    public function testAnAbsentBreakpointEmitsNothingSoItInheritsTheOneBelow(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'text', 'span' => ['base' => 48, 'lg' => 24]]]],
            [],
            'fr',
        );

        self::assertStringNotContainsString('--span-md', $grid['zones'][0]['spanStyle']);
    }

    /**
     * Blocks are the one part written raw, so this is where the sanitiser has
     * to run — the same path the plain block column takes.
     */
    /**
     * A declaration rather than a Tailwind class, for the reason spans are
     * custom properties: `aspect-square` and `aspect-[3/4]` appear in no source
     * Tailwind reads, so choosing them in PHP would emit nothing and the crop
     * would silently not happen.
     */
    public function testACroppedZoneCarriesItsShapeAsACssDeclaration(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'media', 'ratio' => '1x1']],
            ],
            [],
            'fr',
        );

        self::assertSame('aspect-ratio: 1 / 1;', $grid['zones'][0]['ratioStyle']);
    }

    /**
     * Empty rather than `aspect-ratio: auto`, so the template can test it and
     * a picture that was published uncropped stays byte-for-byte uncropped.
     */
    public function testAZoneAtItsOwnProportionsEmitsNoShapeAtAll(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media']]],
            [],
            'fr',
        );

        self::assertSame('', $grid['zones'][0]['ratioStyle']);
    }

    public function testTextGoesThroughTheBlockSanitiser(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'text']]],
            ['zones' => ['z1' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => '<a href="javascript:alert(1)">clic</a>']],
            ]]]],
            'fr',
        );

        self::assertStringNotContainsString('javascript:', (string) $grid['zones'][0]['html']);
    }

    // ── Video ─────────────────────────────────────────────────────────────

    public function testAKnownVideoAddressBecomesAnEmbed(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'video']]],
            ['zones' => ['z1' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']]],
            'fr',
        );

        self::assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            $grid['zones'][0]['video']['embedUrl'],
        );
    }

    /**
     * An address no provider claims must not reach an iframe — but it should
     * still reach the reader, which is why the raw url travels beside the
     * embed rather than instead of it.
     */
    public function testAnUnknownVideoHostIsNotEmbeddedButIsStillOffered(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'video']]],
            ['zones' => ['z1' => ['url' => 'https://example.org/video']]],
            'fr',
        );

        self::assertNull($grid['zones'][0]['video']);
        self::assertSame('https://example.org/video', $grid['zones'][0]['url']);
    }

    public function testAVideoAddressWithAnUnsafeSchemeSurvivesNeitherWay(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'video']]],
            ['zones' => ['z1' => ['url' => 'javascript:alert(1)']]],
            'fr',
        );

        self::assertNull($grid['zones'][0]['video']);
        self::assertNull($grid['zones'][0]['url'], 'the normaliser refused it before it got here');
    }

    // ── Targets that are gone ─────────────────────────────────────────────

    /**
     * A picture deleted from the library since the zone was configured should
     * leave a gap, not a broken image.
     */
    public function testAMediaZonePointingAtNothingResolvesToNothing(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media', 'mediaId' => 999999]]],
            [],
            'fr',
        );

        self::assertNull($grid['zones'][0]['media']);
    }

    public function testAPostZonePointingAtNothingResolvesToNothing(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'post', 'postId' => 999999]]],
            [],
            'fr',
        );

        self::assertNull($grid['zones'][0]['post']);
    }

    /** Only the key its type names is filled; the rest stay null. */
    public function testAZoneOnlyCarriesWhatItsTypeMeans(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'text']]],
            ['zones' => ['z1' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'x']]]]]],
            'fr',
        );

        $zone = $grid['zones'][0];

        self::assertNotNull($zone['html']);
        self::assertNull($zone['media']);
        self::assertNull($zone['post']);
        self::assertNull($zone['video']);
    }

    // ── When there is nothing to show ─────────────────────────────────────

    /** `build` is what the public page calls: null means "render the blocks". */
    public function testADisabledGridIsNothingAtAll(): void
    {
        self::assertNull($this->gridViewBuilder->build(
            ['enabled' => false, 'zones' => [['id' => 'z1', 'type' => 'text']]],
            [],
            'fr',
        ));
    }

    public function testAnEnabledGridWithNoZonesIsTreatedAsOff(): void
    {
        self::assertNull($this->gridViewBuilder->build(['enabled' => true, 'zones' => []], [], 'fr'));
    }

    /**
     * A post that never touched the grid must render exactly as it did before
     * the feature existed.
     */
    public function testAPostWithNoGridFallsBackToItsBlocks(): void
    {
        self::assertNull($this->gridViewBuilder->build([], [], 'fr'));
    }

    public function testTheEditorGetsAShapeEvenWhenTheGridIsOff(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor([], [], 'fr');

        self::assertFalse($grid['enabled']);
        self::assertSame([], $grid['zones']);
        self::assertSame(4, $grid['snap']);
    }
}
