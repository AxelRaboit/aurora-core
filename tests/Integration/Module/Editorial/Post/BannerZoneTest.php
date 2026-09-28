<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

use function mb_substr_count;

/**
 * A page banner set in the body as a grid zone: the same design, words,
 * slides and carousel as the header above the page, at its zone's width.
 */
final class BannerZoneTest extends IntegrationTestCase
{
    private GridViewBuilder $builder;

    private GridNormalizer $normalizer;

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->builder = self::getContainer()->get(GridViewBuilder::class);
        $this->normalizer = self::getContainer()->get(GridNormalizer::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testTheZoneKeepsABannerLayoutAndItsWords(): void
    {
        $layout = $this->normalizer->normalizeLayout(['enabled' => true, 'zones' => [$this->zone()]]);
        $content = $this->normalizer->normalizeContent(['zones' => ['z1' => $this->words()]], $layout);

        $banner = $layout['zones'][0]['banner'];
        self::assertTrue($banner['enabled'], 'the zone being there is the switch');
        self::assertSame('lg', $banner['height']);
        self::assertCount(1, $banner['slides']);
        self::assertSame('Un bandeau', $content['zones']['z1']['banner']['items']['a1']['title']);
        self::assertSame('Deuxième', $content['zones']['z1']['banner']['slides']['s1']['items']['b1']['title']);
    }

    public function testOtherZonesCarryNoBanner(): void
    {
        $layout = $this->normalizer->normalizeLayout(['zones' => [['id' => 'z2', 'type' => 'text', 'banner' => ['height' => 'lg']]]]);

        self::assertNull($layout['zones'][0]['banner']);
        self::assertNull($this->normalizer->normalizeContent(['zones' => ['z2' => ['banner' => ['items' => []]]]], $layout)['zones']['z2']['banner']);
    }

    public function testItDrawsTheBannerWithoutTakingThePageTitle(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-banner-carousel', $html, "its slides turn like the header's");
        self::assertSame(0, mb_substr_count($html, '<h1'), "the page keeps the header's h1");
        self::assertMatchesRegularExpression('/<h2[^>]*>Un bandeau<\/h2>/', $html);
        self::assertMatchesRegularExpression('/<h2[^>]*>Deuxième<\/h2>/', $html, 'each slide names its section');
        self::assertStringNotContainsString(' mb-8', $html, 'the zone owns its spacing');
    }

    public function testTheEditorGetsTheDesignBack(): void
    {
        $grid = $this->builder->buildForEditor(
            ['enabled' => true, 'zones' => [$this->zone()]],
            ['zones' => ['z1' => $this->words()]],
            'fr',
        );

        $banner = $grid['zones'][0]['banner'];
        self::assertSame('s1', $banner['slides'][0]['id']);
        self::assertArrayHasKey('media', $banner['background'], 'resolved for the pickers');
    }

    public function testTheEditorGetsThisLanguagesPicturesResolved(): void
    {
        $content = $this->builder->contentForEditor(
            ['enabled' => true, 'zones' => [$this->zone()]],
            ['zones' => ['z1' => $this->words()]],
        );

        self::assertArrayHasKey('media', $content['zones']['z1']['banner']['background']);
        self::assertArrayHasKey('media', $content['zones']['z1']['banner']['slides']['s1']['background']);
    }

    /** @return array<string, mixed> */
    private function zone(): array
    {
        return [
            'id' => 'z1',
            'type' => 'banner',
            'banner' => [
                'height' => 'lg',
                'items' => [['id' => 'a1', 'type' => 'text']],
                'slides' => [['id' => 's1', 'items' => [['id' => 'b1', 'type' => 'text']], 'accentColor' => '#bd4a55']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function words(): array
    {
        return ['banner' => [
            'items' => ['a1' => ['title' => 'Un bandeau']],
            'slides' => ['s1' => ['items' => ['b1' => ['title' => 'Deuxième']]]],
        ]];
    }

    private function render(): string
    {
        $grid = $this->builder->build(['enabled' => true, 'zones' => [$this->zone()]], ['zones' => ['z1' => $this->words()]], 'fr');

        self::assertNotNull($grid);

        return $this->twig->render('Frontend/themes/default/editorial/post/_grid_zone.html.twig', ['zone' => $grid['zones'][0], 'locale' => 'fr']);
    }
}
