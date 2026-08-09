<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

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

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
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

    /**
     * A stack's children are resolved like any other zone — the whole point of
     * the type is that what fills a stacked zone is not a special case.
     */
    public function testAStacksChildrenAreResolvedLikeAnyOtherZone(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'zones' => [[
                    'id' => 'holder',
                    'type' => 'stack',
                    'span' => ['lg' => 24],
                    'children' => [
                        ['id' => 'top', 'type' => 'text', 'span' => ['lg' => 24]],
                        ['id' => 'bottom', 'type' => 'text', 'span' => ['lg' => 24]],
                    ],
                ]],
            ],
            ['zones' => [
                'top' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Au-dessus']]]],
                'bottom' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'En dessous']]]],
            ]],
            'fr',
        );

        $children = $grid['zones'][0]['children'];

        self::assertCount(2, $children);
        self::assertStringContainsString('Au-dessus', (string) $children[0]['html']);
        self::assertStringContainsString('En dessous', (string) $children[1]['html']);
    }

    /**
     * `flex-basis: 0` is what turns the grow factors into exact proportions
     * rather than a split of leftover space. There is deliberately no
     * `min-height: 0` beside it: without that floor a child could be squeezed
     * under its own content, which is the clipped-text failure this whole
     * approach exists to avoid. Proportions when the content fits, growth when
     * it does not.
     */
    public function testAStackedChildCarriesItsShareOfTheHeight(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'zones' => [[
                    'id' => 'holder',
                    'type' => 'stack',
                    'children' => [
                        ['id' => 'top', 'type' => 'text', 'span' => ['lg' => 24]],
                        ['id' => 'bottom', 'type' => 'text', 'span' => ['lg' => 24]],
                    ],
                ]],
            ],
            [],
            'fr',
        );

        foreach ($grid['zones'][0]['children'] as $child) {
            self::assertSame('flex-grow: 24; flex-basis: 0;', $child['shareStyle']);
            self::assertStringNotContainsString('min-height', $child['shareStyle']);
        }
    }

    /**
     * Blocks are the one part written raw, so this is where the sanitiser has
     * to run — the same path the plain block column took.
     */
    /**
     * `fill` is a height, not a ratio, so it emits no declaration — the
     * template turns it into classes instead. Asserting the empty string is
     * what stops someone "fixing" it later by inventing an `aspect-ratio` for
     * it, which would pin the very height it exists to inherit.
     */
    public function testFillingAZoneStatesNoRatioAtAll(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media', 'ratio' => 'fill']]],
            [],
            'fr',
        );

        self::assertSame('fill', $grid['zones'][0]['ratio']);
        self::assertSame('', $grid['zones'][0]['ratioStyle']);
    }

    /**
     * The point of `fill`: not "half each", which leaves a hole under three
     * lines of text, but "the text takes what it needs and the picture has the
     * rest". Shares stop applying the moment one zone claims the remainder —
     * a zone cannot both leave room and take everything left.
     */
    public function testAFillingZoneTakesTheRemainderAndItsNeighboursTheirOwnHeight(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'zones' => [[
                    'id' => 'holder',
                    'type' => 'stack',
                    'children' => [
                        ['id' => 'words', 'type' => 'text', 'span' => ['lg' => 24]],
                        ['id' => 'picture', 'type' => 'media', 'span' => ['lg' => 24], 'ratio' => 'fill'],
                    ],
                ]],
            ],
            [],
            'fr',
        );

        [$words, $picture] = $grid['zones'][0]['children'];

        self::assertSame('flex-grow: 0; flex-basis: auto;', $words['shareStyle']);
        self::assertSame('flex-grow: 1; flex-basis: 0;', $picture['shareStyle']);
    }

    /** Without one, the shares are what divides the height, exactly as before. */
    public function testSharesStillApplyWhenNoZoneFills(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'zones' => [[
                    'id' => 'holder',
                    'type' => 'stack',
                    'children' => [
                        ['id' => 'top', 'type' => 'text', 'span' => ['lg' => 36]],
                        ['id' => 'bottom', 'type' => 'text', 'span' => ['lg' => 12]],
                    ],
                ]],
            ],
            [],
            'fr',
        );

        self::assertSame('flex-grow: 36; flex-basis: 0;', $grid['zones'][0]['children'][0]['shareStyle']);
        self::assertSame('flex-grow: 12; flex-basis: 0;', $grid['zones'][0]['children'][1]['shareStyle']);
    }

    /**
     * The library wins whenever it has an answer: a document carries a focal
     * point, a variant sized for the slot and an alt of its own, and an address
     * carries none of that. It stands in rather than competing.
     */
    public function testAPickedDocumentIsPreferredToAnAddress(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'media', 'mediaUrl' => 'https://example.test/stand-in.jpg']],
            ],
            [],
            'fr',
        );

        // No document exists for a null id, so the address answers.
        self::assertSame('https://example.test/stand-in.jpg', $grid['zones'][0]['media']['url']);
        self::assertSame('50% 50%', $grid['zones'][0]['media']['focalPosition'], 'an address says where a picture is, not what matters in it');
    }

    public function testAMediaZoneWithNeitherResolvesToNothing(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media']]],
            [],
            'fr',
        );

        self::assertNull($grid['zones'][0]['media']);
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

    /**
     * A media zone renders an `<img>`, so what it points at has to be an
     * image. The picker only offers those, but a fixture, an API write, or a
     * document whose file is replaced afterwards all reach past it — and this
     * is the one that reads as nothing at all rather than as an error: a
     * browser handed an mp4 in an `<img>` shows a broken image and says
     * nothing anywhere.
     */
    public function testAMediaZonePointingAtAVideoResolvesToNothing(): void
    {
        $video = $this->document('video/mp4', 'ged/2026/08/demo-video.mp4');

        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media', 'mediaId' => $video]]],
            [],
            'fr',
        );

        self::assertNull($grid['zones'][0]['media']);
    }

    /**
     * The other half of the check, and the one that would catch a guard
     * written too wide: refusing everything would pass the test above.
     */
    public function testAMediaZonePointingAtAPictureStillResolves(): void
    {
        $picture = $this->document('image/jpeg', 'ged/2026/08/photo.jpg');

        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media', 'mediaId' => $picture]]],
            [],
            'fr',
        );

        self::assertNotNull($grid['zones'][0]['media']);
        self::assertStringContainsString('photo.jpg', (string) $grid['zones'][0]['media']['url']);
    }

    /**
     * The library keeps documents with no file on purpose, so the upload flow
     * has something to be tested against. One of those in a media zone used to
     * produce `<img src="">` — a broken image, not an absent one.
     */
    public function testAMediaZoneWhoseDocumentHasNoFileResolvesToNothing(): void
    {
        $fileless = $this->document('image/jpeg', null);

        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media', 'mediaId' => $fileless]]],
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

    /**
     * Flushed rather than only persisted: the builder resolves ids through the
     * repository, so the row has to exist and to have an id.
     */
    private function document(string $mimeType, ?string $filePath): int
    {
        $document = new Document();
        $document->setTitle('Média');
        $document->setMimeType($mimeType);
        $document->setFilePath($filePath);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return (int) $document->getId();
    }
}
