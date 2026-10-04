<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

/**
 * The one zone with nothing to write in it.
 *
 * It lists the headings of the text zones around it, and the anchors it links
 * to are written into those zones on the way past - so what is worth checking
 * is that the two halves agree: every entry points at something, and nothing
 * is added to a page that did not ask for a summary.
 */
final class GridTableOfContentsTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->gridViewBuilder = self::getContainer()->get(GridViewBuilder::class);
    }

    public function testItListsTheHeadingsAndAnchorsThem(): void
    {
        $grid = $this->build(withToc: true);

        self::assertNotNull($grid);

        $toc = $grid['zones'][0]['toc'];
        self::assertCount(3, $toc);
        self::assertSame(['section-1', 2, 'Tarifs'], [$toc[0]['id'], $toc[0]['level'], $toc[0]['text']]);
        self::assertSame(3, $toc[1]['level'], 'a sub-heading keeps its rank, so the list can indent it');
        // Two sections of the same name: numbered ids cannot collide, where
        // ids slugged from the words would send both links to the first.
        self::assertSame('Tarifs', $toc[2]['text']);
        self::assertNotSame($toc[0]['id'], $toc[2]['id']);

        foreach ($toc as $heading) {
            self::assertStringContainsString(
                sprintf('id="%s"', $heading['id']),
                $grid['zones'][1]['html'],
                'a summary entry with nothing to land on',
            );
        }
    }

    /**
     * A heading set in a stack, beside a picture, is a section of the page
     * like any other, and is numbered where a reader meets it.
     */
    public function testAHeadingInsideAStackIsListed(): void
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [
                ['id' => 'z0', 'type' => 'toc'],
                ['id' => 's1', 'type' => 'stack', 'children' => [['id' => 'z1', 'type' => 'text']]],
                ['id' => 'z2', 'type' => 'text'],
            ]],
            ['zones' => [
                'z1' => ['blocks' => [['type' => 'header', 'data' => ['level' => 2, 'text' => 'Ce que je fais']]]],
                'z2' => ['blocks' => [['type' => 'header', 'data' => ['level' => 2, 'text' => 'Pour qui']]]],
            ]],
            'fr',
        );

        self::assertNotNull($grid);
        self::assertSame(['Ce que je fais', 'Pour qui'], array_column($grid['zones'][0]['toc'], 'text'));
        self::assertStringContainsString('<h2 id="section-1">', $grid['zones'][1]['children'][0]['html']);
        self::assertStringContainsString('<h2 id="section-2">', $grid['zones'][2]['html']);
    }

    /** The markup of a page that asked for nothing is left exactly as it was. */
    public function testAPageWithNoSummaryKeepsItsHeadingsBare(): void
    {
        $grid = $this->build(withToc: false);

        self::assertNotNull($grid);
        self::assertStringContainsString('<h2>Tarifs</h2>', $grid['zones'][0]['html']);
        self::assertStringNotContainsString('id="section-', $grid['zones'][0]['html']);
    }

    /**
     * The same summary as a row of pills: every heading a link to its anchor,
     * the second level only quieter, and no list down the page.
     */
    public function testTheSummaryCanBeARowOfPills(): void
    {
        $grid = $this->build(withToc: true, tocLayout: 'pills');
        self::assertNotNull($grid);

        $html = self::getContainer()->get(Environment::class)->render(
            'Frontend/themes/default/editorial/post/_grid.html.twig',
            ['grid' => $grid, 'locale' => 'fr'],
        );

        self::assertSame(3, mb_substr_count($html, 'rounded-full border border-card-line'));
        self::assertStringContainsString('href="#section-1"', $html);
        self::assertStringNotContainsString('<ol class="m-0 list-none space-y-2', $html);
    }

    /** The numbered index: 01 for a section, 01.1 for the heading under it. */
    public function testTheSummaryCanBeANumberedIndex(): void
    {
        $html = $this->renderGrid($this->build(withToc: true, tocLayout: 'index'));

        self::assertMatchesRegularExpression('#>\s*01\s*</span>\s*<span[^>]*>\s*Tarifs#', $html);
        self::assertMatchesRegularExpression('#>\s*01\.1\s*</span>\s*<span[^>]*>\s*À la journée#', $html);
        self::assertMatchesRegularExpression('#>\s*02\s*</span>\s*<span[^>]*>\s*Tarifs#', $html);
    }

    /** The bar that follows the reading is drawn only when the zone asks for it. */
    public function testTheFollowingBarIsOptIn(): void
    {
        self::assertStringNotContainsString('data-toc-follow', $this->renderGrid($this->build(withToc: true)));

        $html = $this->renderGrid($this->build(withToc: true, follow: true));
        self::assertStringContainsString('data-toc-follow="z0-nav"', $html);
        self::assertStringContainsString('id="z0-nav"', $html);
        self::assertSame(6, mb_substr_count($html, 'data-toc-link'), 'three links in the summary, three in the bar');
    }

    public function testAnUnknownLayoutFallsBackToTheList(): void
    {
        $grid = $this->build(withToc: true, tocLayout: 'bogus');
        self::assertNotNull($grid);

        self::assertSame('list', $grid['zones'][0]['options']['tocLayout']);
    }

    /** @return array<string, mixed>|null */
    /** @param array<string, mixed>|null $grid */
    private function renderGrid(?array $grid): string
    {
        self::assertNotNull($grid);

        return self::getContainer()->get(Environment::class)->render(
            'Frontend/themes/default/editorial/post/_grid.html.twig',
            ['grid' => $grid, 'locale' => 'fr'],
        );
    }

    private function build(bool $withToc, string $tocLayout = 'list', bool $follow = false): ?array
    {
        $zones = [['id' => 'z1', 'type' => 'text']];

        if ($withToc) {
            array_unshift($zones, ['id' => 'z0', 'type' => 'toc', 'options' => ['tocLayout' => $tocLayout, 'tocFollow' => $follow]]);
        }

        return $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => $zones],
            ['zones' => ['z1' => ['blocks' => [
                ['type' => 'header', 'data' => ['level' => 2, 'text' => 'Tarifs']],
                ['type' => 'header', 'data' => ['level' => 3, 'text' => 'À la journée']],
                ['type' => 'paragraph', 'data' => ['text' => 'Une phrase.']],
                ['type' => 'header', 'data' => ['level' => 2, 'text' => 'Tarifs']],
            ]]]],
            'fr',
        );
    }
}
