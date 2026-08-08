<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;

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

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->bannerViewBuilder = static::getContainer()->get(BannerViewBuilder::class);
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
}
