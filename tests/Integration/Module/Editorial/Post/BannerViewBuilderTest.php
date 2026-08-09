<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The join between the two halves of a banner, and the only reason the Twig
 * partial did not change when the storage split in two.
 *
 * It had no test of its own: the split was covered at the normaliser and at
 * the write boundary, and this — the piece that decides what a template
 * actually receives — was only ever exercised sideways, through a page render
 * that would have passed on a merge quietly dropping half its fields.
 */
final class BannerViewBuilderTest extends IntegrationTestCase
{
    private BannerViewBuilder $bannerViewBuilder;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->bannerViewBuilder = static::getContainer()->get(BannerViewBuilder::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnItemCarriesBothItsDesignAndItsWords(): void
    {
        $banner = $this->bannerViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'items' => [['id' => 'a1', 'type' => 'text', 'titleSize' => 'xl', 'titleColor' => '#ffffff']],
            ],
            ['items' => ['a1' => ['title' => 'Bonjour', 'description' => 'Sous-titre']]],
        );

        $item = $banner['items'][0];

        // The template reads these off one object. It never learns they were
        // stored in two places, which is the whole point of merging here.
        self::assertSame('Bonjour', $item['title']);
        self::assertSame('Sous-titre', $item['description']);
        self::assertSame('xl', $item['titleSize']);
        self::assertSame('#ffffff', $item['titleColor']);
    }

    public function testTheSpanBecomesCustomPropertiesTheGridCanRead(): void
    {
        $banner = $this->bannerViewBuilder->buildForEditor(
            ['enabled' => true, 'items' => [['id' => 'a1', 'type' => 'text', 'span' => ['base' => 48, 'lg' => 24]]]],
            [],
        );

        $style = $banner['items'][0]['spanStyle'];

        self::assertStringContainsString('--span-base: 48;', $style);
        self::assertStringContainsString('--span-lg: 24;', $style);
        // An absent breakpoint emits nothing, which is what makes it inherit
        // the one below through the variable's own fallback chain.
        self::assertStringNotContainsString('--span-md', $style);
    }

    /**
     * A banner replaces the page's own heading, so one of its titles has to
     * become the `<h1>`. This is computed from the words, not the design —
     * a language nobody has written yet has no title to promote.
     */
    public function testTheHeadingIsTheFirstTitleThatExistsInThisLanguage(): void
    {
        $layout = [
            'enabled' => true,
            'items' => [
                ['id' => 'a1', 'type' => 'image'],
                ['id' => 'a2', 'type' => 'text'],
                ['id' => 'a3', 'type' => 'text'],
            ],
        ];

        $french = $this->bannerViewBuilder->buildForEditor(
            $layout,
            ['items' => ['a2' => ['title' => 'Deuxième'], 'a3' => ['title' => 'Troisième']]],
        );

        self::assertSame(1, $french['headingIndex'], 'the image is skipped, the first written title wins');

        $german = $this->bannerViewBuilder->buildForEditor($layout, []);

        self::assertNull(
            $german['headingIndex'],
            'an untranslated banner promotes nothing, so the page keeps its own h1',
        );
    }

    public function testAGradientNeedsBothStopsBeforeItBecomesCss(): void
    {
        $half = $this->bannerViewBuilder->buildForEditor(
            ['enabled' => true, 'background' => ['type' => 'gradient', 'gradientFrom' => '#000000']],
            [],
        );

        self::assertNull($half['background']['fillStyle'], 'one stop is a solid fill nobody asked for');

        $full = $this->bannerViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'background' => [
                    'type' => 'gradient',
                    'gradientFrom' => '#000000',
                    'gradientTo' => '#ffffff',
                    'gradientAngle' => 135,
                ],
            ],
            [],
        );

        self::assertSame(
            'background-image: linear-gradient(135deg, #000000, #ffffff);',
            $full['background']['fillStyle'],
        );
    }

    public function testASolidFillWithNoColourProducesNoStyle(): void
    {
        $banner = $this->bannerViewBuilder->buildForEditor(
            ['enabled' => true, 'background' => ['type' => 'solid']],
            [],
        );

        self::assertNull($banner['background']['fillStyle']);
    }

    /**
     * `build` is what the public page calls, and it answers null so the
     * template can fall back to the plain header.
     */
    public function testADisabledBannerIsNothingAtAll(): void
    {
        self::assertNull($this->bannerViewBuilder->build(
            ['enabled' => false, 'items' => [['id' => 'a1', 'type' => 'text']]],
            ['items' => ['a1' => ['title' => 'Bonjour']]],
        ));
    }

    /**
     * An author who cleared everything meant to remove the banner, not to
     * publish a coloured void.
     */
    public function testAnEnabledBannerWithNothingInItIsTreatedAsOff(): void
    {
        self::assertNull($this->bannerViewBuilder->build(['enabled' => true], []));
    }

    public function testAnEnabledBannerWithOnlyABackgroundStillRenders(): void
    {
        $banner = $this->bannerViewBuilder->build(
            ['enabled' => true, 'background' => ['type' => 'solid', 'color' => '#123456']],
            [],
        );

        self::assertNotNull($banner, 'a background is content: it is what a decorative header is made of');
    }

    /**
     * The editor asks for a preview while the banner is still switched off or
     * half-composed. Answering "nothing" there would look like a bug rather
     * than a state.
     */
    public function testTheEditorGetsAShapeEvenWhenTheBannerIsOff(): void
    {
        $banner = $this->bannerViewBuilder->buildForEditor([], []);

        self::assertFalse($banner['enabled']);
        self::assertSame([], $banner['items']);
        self::assertArrayHasKey('background', $banner);
        self::assertNull($banner['logo']);
    }

    /**
     * Text for an item the layout dropped must not reappear on the page
     * through the merge — the normaliser drops it, and this is what proves
     * the builder goes through the normaliser rather than around it.
     */
    public function testWordsWithNoItemLeftDoNotComeBackThroughTheMerge(): void
    {
        $banner = $this->bannerViewBuilder->buildForEditor(
            ['enabled' => true, 'items' => [['id' => 'a1', 'type' => 'text']]],
            ['items' => [
                'a1' => ['title' => 'Gardé'],
                'orphan' => ['title' => 'Supprimé'],
            ]],
        );

        self::assertCount(1, $banner['items']);
        self::assertSame('Gardé', $banner['items'][0]['title']);
    }

    /**
     * The colours land in a `style` attribute. The builder assembles that
     * string itself, so it has to be reached only by values the normaliser
     * already reduced to a hex.
     */
    public function testAColourTheNormaliserRejectsNeverReachesTheStyle(): void
    {
        $banner = $this->bannerViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'background' => ['type' => 'solid', 'color' => 'red; background: url(javascript:alert(1))'],
            ],
            [],
        );

        self::assertNull($banner['background']['fillStyle']);
    }

    /**
     * The background, the logo and an image item all end up in an `<img>`, so
     * all three have to hold an image. The picker only offers those, but a
     * fixture, an API write, or a document whose file is replaced afterwards
     * reach past it — and a browser handed an mp4 in an `<img>` shows a broken
     * image and says nothing anywhere.
     */
    public function testNoneOfTheThreePicturesResolvesToAVideo(): void
    {
        $video = $this->document('video/mp4', 'ged/2026/08/demo-video.mp4');

        $banner = $this->bannerViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'logoMediaId' => $video,
                'background' => ['mediaId' => $video],
                'items' => [['id' => 'a1', 'type' => 'image', 'mediaId' => $video]],
            ],
            [],
        );

        self::assertNull($banner['background']['media']);
        self::assertNull($banner['logo']);
        self::assertNull($banner['items'][0]['media']);
    }

    /**
     * The other half of the check, and the one that would catch a guard
     * written too wide: refusing everything would pass the test above.
     */
    public function testAPictureStillResolvesInAllThreePlaces(): void
    {
        $picture = $this->document('image/jpeg', 'ged/2026/08/photo.jpg');

        $banner = $this->bannerViewBuilder->buildForEditor(
            [
                'enabled' => true,
                'logoMediaId' => $picture,
                'background' => ['mediaId' => $picture],
                'items' => [['id' => 'a1', 'type' => 'image', 'mediaId' => $picture]],
            ],
            [],
        );

        self::assertStringContainsString('photo.jpg', (string) $banner['background']['media']['url']);
        self::assertStringContainsString('photo.jpg', (string) $banner['logo']['url']);
        self::assertStringContainsString('photo.jpg', (string) $banner['items'][0]['media']['url']);
    }

    /**
     * The library keeps documents with no file on purpose, so the upload flow
     * has something to be tested against. One of those used to produce
     * `<img src="">`, which is worse than an absent picture: an empty src
     * resolves to the page's own address and fetches it a second time.
     */
    public function testADocumentWithNoFileResolvesToNothing(): void
    {
        $fileless = $this->document('image/jpeg', null);

        $banner = $this->bannerViewBuilder->buildForEditor(
            ['enabled' => true, 'background' => ['mediaId' => $fileless]],
            [],
        );

        self::assertNull($banner['background']['media']);
    }

    /**
     * What makes answering null acceptable for a hero background: the fill is
     * resolved separately, so a banner that has one still renders the header
     * its author designed rather than a transparent box.
     */
    public function testAFillSurvivesABackgroundPictureThatCannotBeDrawn(): void
    {
        $video = $this->document('video/mp4', 'ged/2026/08/demo-video.mp4');

        $banner = $this->bannerViewBuilder->build(
            [
                'enabled' => true,
                'background' => ['type' => 'solid', 'color' => '#123456', 'mediaId' => $video],
            ],
            [],
        );

        self::assertNotNull($banner);
        self::assertNull($banner['background']['media']);
        self::assertSame('background-color: #123456;', $banner['background']['fillStyle']);
    }

    /**
     * And the other end of it: a banner whose only content was that picture has
     * nothing left, so `build` switches it off and the page puts back its own
     * header — and with it the `<h1>` the banner was going to carry.
     */
    public function testABannerLeftWithNothingButAnUndrawablePictureIsOff(): void
    {
        $video = $this->document('video/mp4', 'ged/2026/08/demo-video.mp4');

        self::assertNull($this->bannerViewBuilder->build(
            ['enabled' => true, 'background' => ['mediaId' => $video]],
            [],
        ));
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
