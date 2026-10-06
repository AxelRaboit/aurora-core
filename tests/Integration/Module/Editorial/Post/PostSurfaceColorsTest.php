<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The colours a publication sets for itself alone, all the way to the served
 * page.
 *
 * Tested here rather than as a unit test because the gap was precisely between
 * the two: the column, the DTO and the edit screen were wired, and nothing read
 * the colour at render time - the feature was complete in the database and
 * invisible on the site. So what is checked: that the colour crosses the whole
 * chain, from the input array to the response's CSS.
 *
 * The resolution itself - precedence, inheritance, contrast - is covered as a
 * unit by ThemeContextSurfacesTest, which does not need a database.
 */
final class PostSurfaceColorsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** Without a chosen colour, the page comes out exactly as before. */
    public function testAPublicationWithoutColoursEmitsNoSurfaceRule(): void
    {
        $this->published('Page ordinaire');

        $this->client->request('GET', '/fr/surface-type/page-ordinaire');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            '.aurora-surface-header{',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * The heart of the feature: the colour set on the publication reaches its
     * page's CSS, and carries its contrasted set of tokens with it.
     */
    public function testTheTopbarColourReachesTheServedPage(): void
    {
        $this->published('Page repeinte', ['headerColor' => '#0f172a']);

        $this->client->request('GET', '/fr/surface-type/page-repeinte');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('html[data-theme] .aurora-surface-header{', $html);
        self::assertStringContainsString('--th-surface-bg: #0f172a;', $html);
        // Without this token, the topbar's labels would stay dark on dark -
        // it is what separates "repainting" from "making unreadable".
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $html);
    }

    /** The three surfaces stay independent all the way into the served page. */
    public function testTheThreeSurfacesArePaintedIndependently(): void
    {
        $this->published('Page tricolore', [
            'backgroundColor' => '#fef9c3',
            'headerColor' => '#0f172a',
            'footerColor' => '#1f2937',
        ]);

        $this->client->request('GET', '/fr/surface-type/page-tricolore');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('--th-surface-bg: #fef9c3;', $html);
        self::assertStringContainsString('--th-surface-bg: #0f172a;', $html);
        self::assertStringContainsString('--th-surface-bg: #1f2937;', $html);
    }

    /**
     * A publication's colour does not leak onto the others.
     *
     * The CSS is set in the rendered page's `<head>`, so nothing shares it; the
     * test exists because an implementation through a theme variable would
     * have shared it, and nothing would have flagged it.
     */
    public function testTheColourStaysOnItsOwnPublication(): void
    {
        $this->published('Page peinte', ['headerColor' => '#0f172a']);
        $this->published('Page voisine');

        $this->client->request('GET', '/fr/surface-type/page-voisine');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('#0f172a', (string) $this->client->getResponse()->getContent());
    }

    /** A colour that is not a `#rrggbb` does not reach the page. */
    public function testAMalformedColourIsRefusedAtTheWriteBoundary(): void
    {
        $post = $this->published('Page douteuse', ['headerColor' => 'red; background: url(x)']);

        self::assertNull($post->getHeaderColor());

        $this->client->request('GET', '/fr/surface-type/page-douteuse');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('.aurora-surface-header{', (string) $this->client->getResponse()->getContent());
    }

    // ── The accent colour, scoped rather than global ───────────────────────

    /** Without a chosen accent, the page carries no scoped rule. */
    public function testAPublicationWithoutAnAccentEmitsNoScopedRule(): void
    {
        $this->published('Page sans accent');

        $this->client->request('GET', '/fr/surface-type/page-sans-accent');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('.aurora-post-accent{', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A publication's accent colour reaches its page, under its own selector
     * rather than `:root` - so the shared topbar and footer are never
     * repainted.
     */
    public function testTheAccentColourReachesItsOwnSelector(): void
    {
        $this->published('Page ocre', ['accentColor' => '#b45309']);

        $this->client->request('GET', '/fr/surface-type/page-ocre');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('.aurora-post-accent{', $html);
        self::assertStringContainsString('--th-accent-500:', $html);
    }

    /** A publication's accent does not leak onto another one. */
    public function testTheAccentStaysOnItsOwnPublication(): void
    {
        $this->published('Page accentuée', ['accentColor' => '#b45309']);
        $this->published('Page voisine bis');

        $this->client->request('GET', '/fr/surface-type/page-voisine-bis');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('.aurora-post-accent{', (string) $this->client->getResponse()->getContent());
    }

    /** An accent colour that is not a `#rrggbb` does not reach the page. */
    public function testAMalformedAccentIsRefusedAtTheWriteBoundary(): void
    {
        $post = $this->published('Page accent douteux', ['accentColor' => 'red; background: url(x)']);

        self::assertNull($post->getAccentColor());

        $this->client->request('GET', '/fr/surface-type/page-accent-douteux');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('.aurora-post-accent{', (string) $this->client->getResponse()->getContent());
    }

    // ── Hovers and card markers, scoped like the accent ───────────────────

    /** Without a choice, the page inherits the theme and carries no rule. */
    public function testAPublicationWithoutAHighlightEmitsNoScopedRule(): void
    {
        $this->published('Page survol hérité');

        $this->client->request('GET', '/fr/surface-type/page-survol-herite');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('aurora-post-highlight', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A publication's neutral reaches its page under its own selector,
     * descendants included: that is what puts it ahead of the theme's setting,
     * which is also set element by element.
     */
    public function testANeutralHighlightReachesItsOwnSelector(): void
    {
        $this->published('Page survol neutre', ['highlight' => 'neutral']);

        $this->client->request('GET', '/fr/surface-type/page-survol-neutre');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('html[data-theme] .aurora-post-highlight,html[data-theme] .aurora-post-highlight *{--th-highlight: var(--th-primary);}', $html);
        self::assertStringContainsString('aurora-post-highlight"', $html);
    }

    public function testACustomHighlightCarriesItsColour(): void
    {
        $post = $this->published('Page survol ocre', ['highlight' => 'custom', 'highlightColor' => '#b45309']);

        self::assertSame('custom', $post->getHighlight());

        $this->client->request('GET', '/fr/surface-type/page-survol-ocre');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--th-highlight: #b45309;', (string) $this->client->getResponse()->getContent());
    }

    /**
     * An unknown mode, or a "custom" without a valid colour, inherits the theme
     * from the moment it is written rather than rendering colourless hovers.
     */
    public function testAnUnusableHighlightIsRefusedAtTheWriteBoundary(): void
    {
        $unknown = $this->published('Page survol inconnu', ['highlight' => 'grey']);
        $colourless = $this->published('Page survol sans couleur', ['highlight' => 'custom', 'highlightColor' => 'red;}</style>']);

        self::assertNull($unknown->getHighlight());
        self::assertNull($colourless->getHighlight());
        self::assertNull($colourless->getHighlightColor());

        $this->client->request('GET', '/fr/surface-type/page-survol-sans-couleur');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('aurora-post-highlight', (string) $this->client->getResponse()->getContent());
    }

    // ── The rest of the theme's colours, and the chrome ────────────────────

    /**
     * Text, lines, headings: the theme colours a publication can repaint on top
     * of its three surfaces, all the way to its page's CSS. An unknown key or a
     * value that is not a `#rrggbb` is dropped.
     */
    public function testTheOtherThemeColoursReachTheServedPage(): void
    {
        $post = $this->published('Page encrée', [
            'backgroundColor' => '#0f172a',
            'colorOverrides' => [
                'text_color' => '#f5f5f4',
                'line_color' => '#7c3aed',
                'heading_color' => '#fbbf24',
                'figure_color' => 'red;}</style>',
                'banner_color' => '#000000',
            ],
        ]);

        self::assertSame(
            ['text_color' => '#f5f5f4', 'line_color' => '#7c3aed', 'heading_color' => '#fbbf24'],
            $post->getColorOverrides(),
        );

        $this->client->request('GET', '/fr/surface-type/page-encree');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('--th-primary: #f5f5f4;', $html);
        self::assertStringContainsString('--color-border: #7c3aed;', $html);
        self::assertStringContainsString('--th-heading: #fbbf24;', $html);
        self::assertStringNotContainsString('</style>;', $html);
    }

    /** By default, the topbar and the footer keep the theme's accent. */
    public function testTheChromeKeepsTheThemeByDefault(): void
    {
        $this->published('Page sobre', ['accentColor' => '#b45309']);

        $this->client->request('GET', '/fr/surface-type/page-sobre');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertDoesNotMatchRegularExpression('/<header class="sticky[^"]*aurora-post-accent/', $html);
        self::assertDoesNotMatchRegularExpression('/<footer class="[^"]*aurora-post-accent/', $html);
    }

    /** When asked, the topbar and the footer take the page's accent and hovers. */
    public function testTheChromeFollowsThePageWhenAsked(): void
    {
        $this->published('Page entière', [
            'accentColor' => '#b45309',
            'highlight' => 'neutral',
            'chromeFollowsPage' => true,
        ]);

        $this->client->request('GET', '/fr/surface-type/page-entiere');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertMatchesRegularExpression('/<header class="sticky[^"]*aurora-post-accent aurora-post-highlight"/', $html);
        self::assertMatchesRegularExpression('/<footer class="[^"]*aurora-post-accent aurora-post-highlight"/', $html);
    }

    /**
     * @param array<string, mixed> $colours
     */
    private function published(string $title, array $colours = []): PostInterface
    {
        $postType = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'surface-type']);

        if (!$postType instanceof PostType) {
            $postType = new PostType();
            $postType->setSlug('surface-type');
            $postType->setLabel('Surface type');
            $this->entityManager->persist($postType);
            $this->entityManager->flush();
            $this->created[] = [PostType::class, (int) $postType->getId()];
        }

        $post = self::getContainer()->get(PostManagerInterface::class)->create(
            self::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'published',
                'translations' => ['fr' => ['title' => $title]],
                ...$colours,
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
