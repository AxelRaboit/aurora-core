<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Banner\BannerNormalizer;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

use function array_column;
use function array_fill;
use function array_keys;
use function array_unique;
use function mb_substr_count;

/**
 * A banner whose slides take turns: the banner itself is the first slide,
 * and any in `slides` follow it in the same place, each with its own
 * background, words and accent.
 */
final class BannerCarouselTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'Frontend/themes/default/editorial/post/_banner.html.twig';

    private BannerViewBuilder $bannerViewBuilder;

    private BannerNormalizer $normalizer;

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->bannerViewBuilder = self::getContainer()->get(BannerViewBuilder::class);
        $this->normalizer = self::getContainer()->get(BannerNormalizer::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testABannerWithoutSlidesIsNoCarousel(): void
    {
        $html = $this->render([]);

        self::assertStringNotContainsString('data-banner-carousel', $html);
        self::assertStringNotContainsString('data-banner-slide', $html);
        self::assertSame(1, mb_substr_count($html, '<h1'));
    }

    public function testEachSlideIsStackedAndOnlyTheFirstShows(): void
    {
        $html = $this->render(['slides' => [$this->slide('s1'), $this->slide('s2')]]);

        self::assertStringContainsString('data-banner-carousel', $html);
        self::assertSame(3, mb_substr_count($html, 'data-banner-slide'));
        self::assertSame(1, mb_substr_count($html, 'data-active'), 'the first, and only it');
        self::assertSame(2, mb_substr_count($html, 'aria-hidden="true" inert'));
        self::assertStringContainsString('Deuxième', $html);
        self::assertStringContainsString('Troisième', $html);
    }

    public function testThePageKeepsOneHeading(): void
    {
        $html = $this->render(['slides' => [$this->slide('s1'), $this->slide('s2')]]);

        self::assertSame(1, mb_substr_count($html, '<h1'), 'the first slide carries the <h1>, the others do not');
        self::assertMatchesRegularExpression('/<h1[^>]*>Bonjour<\/h1>/', $html);
        self::assertMatchesRegularExpression('/<p[^>]*>Deuxième<\/p>/', $html);
    }

    public function testTheSettingsReachThePage(): void
    {
        $html = $this->render([
            'slides' => [$this->slide('s1')],
            'carousel' => ['interval' => 99, 'transition' => 'slide', 'pauseOnHover' => false],
        ]);

        self::assertStringContainsString('data-interval="30"', $html, 'clamped');
        self::assertStringContainsString('data-transition="slide"', $html);
        self::assertStringContainsString('data-pause-on-hover="false"', $html);
        self::assertStringContainsString('data-autoplay="true"', $html, 'on unless turned off');
        self::assertStringContainsString('data-banner-prev', $html);
        self::assertSame(2, mb_substr_count($html, 'data-banner-dot='));
        self::assertStringContainsString('data-banner-pause', $html);
    }

    public function testArrowsDotsAndTheTurningCanEachBeTurnedOff(): void
    {
        $html = $this->render([
            'slides' => [$this->slide('s1')],
            'carousel' => ['autoplay' => false, 'arrows' => false, 'dots' => false],
        ]);

        self::assertStringContainsString('data-autoplay="false"', $html);
        self::assertStringNotContainsString('data-banner-prev', $html);
        self::assertStringNotContainsString('data-banner-dot=', $html);
        self::assertStringNotContainsString('data-banner-pause', $html, 'nothing turns, nothing to pause');
        self::assertStringNotContainsString('data-banner-controls', $html);
    }

    public function testASlideCarriesItsOwnAccent(): void
    {
        $html = $this->render(['slides' => [
            [...$this->slide('s1'), 'accentColor' => '#BD4A55'],
            [...$this->slide('s2'), 'accentColor' => 'red; background: url(x)'],
        ]]);

        self::assertStringContainsString('--th-accent: #bd4a55;', $html);
        self::assertStringNotContainsString('url(x)', $html);
    }

    public function testAPlainBannerCanChangeItsAccentToo(): void
    {
        self::assertStringContainsString('--th-accent: #34d399;', $this->render(['accentColor' => '#34d399']));
    }

    public function testEachSlideKeepsItsWordsPerLanguage(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'a1', 'type' => 'text']],
            'slides' => [$this->slide('s1')],
        ]);
        $texts = $this->normalizer->normalizeTexts([
            'items' => ['a1' => ['title' => 'Bonjour']],
            'slides' => [
                's1' => ['items' => ['b1' => ['title' => 'Deuxième'], 'gone' => ['title' => 'Orphelin']]],
                'removed' => ['items' => ['b1' => ['title' => 'Perdu']]],
            ],
        ], $layout);

        self::assertSame(['s1'], array_keys($texts['slides']), 'the words of a slide that is gone are dropped');
        self::assertSame('Deuxième', $texts['slides']['s1']['items']['b1']['title']);
        self::assertArrayNotHasKey('gone', $texts['slides']['s1']['items']);
    }

    public function testSlidesAreCappedAndTheirIdsKeptUnique(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'slides' => [...array_fill(0, 8, ['id' => 'same', 'items' => []]), 'not a slide'],
        ]);

        self::assertCount(6, $layout['slides']);
        self::assertCount(6, array_unique(array_column($layout['slides'], 'id')));
    }

    public function testTheEditorPreviewsTheSlideItHasOpen(): void
    {
        $banner = $this->bannerViewBuilder->buildForEditor(
            $this->layout(['slides' => [$this->slide('s1'), $this->slide('s2')]]),
            $this->texts(),
            2,
        );

        self::assertSame([], $banner['slides'], 'one slide, no carousel');
        self::assertSame('Troisième', $banner['items'][0]['title']);
        self::assertSame(0, $banner['headingIndex']);
    }

    public function testASlideCanCropItsPictureItsOwnWay(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'background' => ['focalX' => '0.43', 'focalY' => 1.7],
            'slides' => [['id' => 's1', 'background' => ['focalY' => 0.25]]],
        ]);

        self::assertSame(0.43, $layout['background']['focalX']);
        self::assertNull($layout['background']['focalY'], 'outside 0..1 is dropped');
        self::assertNull($layout['slides'][0]['background']['focalX']);
        self::assertSame(0.25, $layout['slides'][0]['background']['focalY']);
    }

    public function testSlidesAloneKeepTheBannerOn(): void
    {
        self::assertNotNull($this->bannerViewBuilder->build(['enabled' => true, 'slides' => [$this->slide('s1')]], []));
    }

    /** @return array<string, mixed> */
    private function slide(string $id): array
    {
        return ['id' => $id, 'items' => [['id' => 'b1', 'type' => 'text']]];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function layout(array $overrides): array
    {
        return [
            'enabled' => true,
            'items' => [['id' => 'a1', 'type' => 'text']],
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function texts(): array
    {
        return [
            'items' => ['a1' => ['title' => 'Bonjour']],
            'slides' => [
                's1' => ['items' => ['b1' => ['title' => 'Deuxième']]],
                's2' => ['items' => ['b1' => ['title' => 'Troisième']]],
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides): string
    {
        $banner = $this->bannerViewBuilder->build($this->layout($overrides), $this->texts());

        self::assertNotNull($banner);

        return $this->twig->render(self::TEMPLATE, ['banner' => $banner]);
    }
}
