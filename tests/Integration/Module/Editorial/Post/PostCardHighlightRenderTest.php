<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

/**
 * A card announces the page it leads to in that page's colour.
 *
 * The colour only exists as a custom property on the card: nothing but the
 * rendered markup says whether it reached the hover classes.
 */
final class PostCardHighlightRenderTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'Frontend/themes/default/editorial/_post_card.html.twig';

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testACardWithAColourCarriesIt(): void
    {
        $html = $this->render(['cardHighlight' => '#bd4a55']);

        self::assertStringContainsString('data-card-highlight', $html);
        self::assertStringContainsString('--card-highlight: #bd4a55;', $html);
    }

    public function testACardWithoutAColourFollowsItsPage(): void
    {
        $html = $this->render(['cardHighlight' => null]);

        self::assertStringNotContainsString('data-card-highlight', $html);
        self::assertStringNotContainsString('--card-highlight', $html);
    }

    /** A payload cached before the key existed still renders. */
    public function testAPayloadWithoutTheKeyStillRenders(): void
    {
        self::assertStringNotContainsString('data-card-highlight', $this->render([]));
    }

    /** @param array<string, mixed> $extra */
    private function render(array $extra): string
    {
        return $this->twig->render(self::TEMPLATE, [
            'locale' => 'fr',
            'post' => [
                'id' => 1,
                'title' => 'Community management',
                'slug' => 'community-management',
                'description' => null,
                'postTypeSlug' => 'services',
                'thumbnailUrl' => null,
                'thumbnailFitClass' => 'object-cover',
                'thumbnailFocalPosition' => '50% 50%',
                ...$extra,
            ],
        ]);
    }
}
