<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

use function mb_substr_count;

/**
 * Slanted colour bands drawn by the site over a banner, instead of painted
 * into its picture: changing their colours or their slant is a setting.
 */
final class BannerStripesTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'Frontend/themes/default/editorial/post/_banner.html.twig';

    private BannerViewBuilder $bannerViewBuilder;

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->bannerViewBuilder = self::getContainer()->get(BannerViewBuilder::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testABannerHasNoStripesUnlessAsked(): void
    {
        self::assertStringNotContainsString('aurora-banner-stripes', $this->render([]));
    }

    public function testOneBandPerColourInTheirOrder(): void
    {
        $html = $this->render(['stripes' => ['enabled' => true, 'colors' => ['#34d399', '#BD4A55', '#cd8f31']]]);

        self::assertSame(3, mb_substr_count($html, '<span style="background-color:'));
        self::assertMatchesRegularExpression('/#34d399.*#bd4a55.*#cd8f31/s', $html, 'lower-cased, in the order given');
    }

    public function testTheSettingsBecomeCustomProperties(): void
    {
        $html = $this->render(['stripes' => ['enabled' => true, 'thickness' => 30, 'gap' => 10, 'angle' => 20, 'offset' => 5, 'opacity' => 80, 'side' => 'start']]);

        self::assertStringContainsString('--stripe-thickness: 30px; --stripe-gap: 10px; --stripe-angle: -20deg; --stripe-offset: 5%; opacity: 0.8;', $html);
        self::assertStringContainsString('data-side="start"', $html);
    }

    public function testNothingButAColourReachesTheStyle(): void
    {
        $html = $this->render(['stripes' => [
            'enabled' => true,
            'colors' => ['red; background: url(x)', '#12345', '#abcdef'],
            'thickness' => '9999',
            'angle' => -500,
        ]]);

        self::assertStringNotContainsString('url(x)', $html);
        self::assertSame(1, mb_substr_count($html, '<span style="background-color:'), 'only the valid hex survives');
        self::assertStringContainsString('--stripe-thickness: 240px;', $html, 'clamped');
        self::assertStringContainsString('--stripe-angle: 60deg;', $html, 'clamped to -60, drawn as its skew');
    }

    public function testAPhoneCanDoWithoutThem(): void
    {
        self::assertStringContainsString('data-hide-on-phone', $this->render(['stripes' => ['enabled' => true, 'hideOnPhone' => true]]));
        self::assertStringNotContainsString('data-hide-on-phone', $this->render(['stripes' => ['enabled' => true]]));
    }

    public function testStripesAloneAreEnoughToDrawTheBanner(): void
    {
        $banner = $this->bannerViewBuilder->build(['enabled' => true, 'items' => [], 'stripes' => ['enabled' => true]], []);

        self::assertNotNull($banner, 'bands on the page colour are a banner someone chose');
        self::assertNull($this->bannerViewBuilder->build(['enabled' => true, 'items' => []], []), 'and without them, still off');
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides): string
    {
        $banner = $this->bannerViewBuilder->build(
            [
                'enabled' => true,
                'items' => [['id' => 'a1', 'type' => 'text']],
                ...$overrides,
            ],
            ['items' => ['a1' => ['title' => 'Bonjour']]],
        );

        self::assertNotNull($banner);

        return $this->twig->render(self::TEMPLATE, ['banner' => $banner]);
    }
}
