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

    /**
     * A horizontal card sets its picture beside the words once the card itself
     * is wide enough, not the screen. Keyed on the screen, two columns at 640px
     * left each card 300px and cut two logos in half; narrower than 28rem the
     * card stays the full card, picture above.
     */
    public function testAHorizontalCardTurnsOnItsOwnWidth(): void
    {
        $html = $this->render(['thumbnailUrl' => '/uploads/logo.webp'], 'horizontal');

        self::assertStringContainsString('flex-col @container', $html);
        self::assertStringContainsString('flex flex-1 flex-col @md:flex-row @md:items-stretch', $html);
        self::assertStringContainsString('shrink-0 @md:w-1/3', $html);
        self::assertStringContainsString('aspect-[16/10] @md:aspect-auto @md:h-full', $html);
        self::assertStringNotContainsString('sm:flex-row', $html);
    }

    private function render(array $extra, ?string $variant = null): string
    {
        return $this->twig->render(self::TEMPLATE, [
            'locale' => 'fr',
            'variant' => $variant,
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
