<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

/**
 * The foot of a banner, which used to end on a hard edge with no way to soften
 * it - so a full-width header sat on the page as a block rather than opening
 * it.
 */
final class BannerFadeTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'Frontend/themes/default/editorial/post/_banner.html.twig';

    private BannerViewBuilder $bannerViewBuilder;

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->bannerViewBuilder = static::getContainer()->get(BannerViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testTheFootOfTheBannerCanDissolveIntoThePage(): void
    {
        self::assertStringContainsString('to-bg', $this->render(['fadeOut' => true]));
    }

    /** Off by default: a hard edge is what every published banner has. */
    public function testABannerKeepsItsEdgeUnlessAsked(): void
    {
        self::assertStringNotContainsString('to-bg', $this->render([]));
    }

    /**
     * A call to action belongs to the sentence that justifies it.
     *
     * As its own item it lands on the row below, which in a banner with a
     * tall picture beside it is half a portrait further down. Both fields
     * already travelled with every item; the text costume simply never drew
     * them.
     */
    public function testAColouredTitleRendersItsSpanAndStaysTheHeading(): void
    {
        $html = $this->renderItems(
            [['id' => 'a1', 'type' => 'text', 'titleFont' => 'playfair-display', 'descriptionSize' => 'xl', 'descriptionFont' => 'inter']],
            ['a1' => ['title' => 'Dev <span class="cdx-text-color" style="color: var(--th-accent)">×</span> Photo', 'description' => 'Une phrase.']],
        );

        self::assertMatchesRegularExpression('#<h1[^>]*>Dev <span class="cdx-text-color" style="color: var\(--th-accent\)[^"]*">×</span> Photo</h1>#', $html);
        self::assertStringContainsString('Playfair Display', $html);
        self::assertStringContainsString('text-xl sm:text-2xl', $html);
        self::assertStringContainsString('Inter', $html);
    }

    /** A title reduced to an empty colour span has no words to be a heading. */
    public function testATitleWithOnlyAnEmptySpanIsNoHeading(): void
    {
        $banner = $this->bannerViewBuilder->build(
            ['enabled' => true, 'items' => [['id' => 'a1', 'type' => 'text']]],
            ['items' => ['a1' => ['title' => '<span class="cdx-text-color" style="color: #ff0000"> </span>']]],
        );

        self::assertNull($banner['headingIndex']);
    }

    public function testATextCarriesItsOwnButton(): void
    {
        $html = $this->renderItems(
            [['id' => 'a1', 'type' => 'text']],
            ['a1' => ['title' => 'Bonjour', 'description' => 'Une phrase.', 'label' => 'Me contacter', 'url' => 'https://example.test']],
        );

        self::assertStringContainsString('Me contacter', $html);
        self::assertStringContainsString('https://example.test', $html);
        // Under the words it answers, not before them.
        self::assertLessThan(
            (int) mb_strpos($html, 'Me contacter'),
            (int) mb_strpos($html, 'Une phrase.'),
        );
    }

    /** A label with nowhere to go draws nothing: a dead control is worse than none. */
    public function testALabelWithoutAnAddressDrawsNoButton(): void
    {
        $html = $this->renderItems(
            [['id' => 'a1', 'type' => 'text']],
            ['a1' => ['title' => 'Bonjour', 'label' => 'Me contacter']],
        );

        self::assertStringNotContainsString('Me contacter', $html);
    }

    /**
     * @param list<array<string, mixed>>          $items
     * @param array<string, array<string, mixed>> $words
     */
    private function renderItems(array $items, array $words): string
    {
        $banner = $this->bannerViewBuilder->build(
            ['enabled' => true, 'items' => $items],
            ['items' => $words],
        );

        self::assertNotNull($banner);

        return $this->twig->render(self::TEMPLATE, ['banner' => $banner]);
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
