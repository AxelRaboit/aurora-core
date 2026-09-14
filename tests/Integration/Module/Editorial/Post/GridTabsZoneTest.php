<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

/**
 * Several bodies of text in one zone, and what arrives before any script runs.
 *
 * That last part is the whole of what the server owes a reader here. The strip
 * of labels is built by `tabs.js`; what has to be right in the markup is that
 * every panel is present and readable without it, and that two tab zones on
 * one page do not fight over an id.
 */
final class GridTabsZoneTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testWithoutAScriptEveryPanelIsThereAndOpen(): void
    {
        $html = $this->render([
            ['id' => 'p1', 'label' => 'Formule simple', 'text' => 'Cinq pages.'],
            ['id' => 'p2', 'label' => 'Formule complète', 'text' => 'Vingt pages.'],
        ]);

        self::assertStringContainsString('data-tabs', $html);
        self::assertSame(2, mb_substr_count($html, 'data-tabs-panel'));
        // Both bodies readable, not one and a control to reach the other.
        self::assertStringContainsString('Cinq pages.', $html);
        self::assertStringContainsString('Vingt pages.', $html);
        self::assertStringNotContainsString('hidden', $html);
    }

    /**
     * Ids come from the stored panel ids, so two tab zones on a page cannot
     * both claim `panel-1` and send a label at the wrong body.
     */
    public function testThePanelIdsCarryTheZoneTheyBelongTo(): void
    {
        $html = $this->render([['id' => 'p1', 'label' => 'Un', 'text' => 'Texte.']]);

        self::assertStringContainsString('id="panel-z1-p1"', $html);
        self::assertStringContainsString('aria-labelledby="tab-z1-p1"', $html);
    }

    /** A panel nobody has written belongs in the editor, not on the page. */
    public function testAnEmptyPanelIsLeftOut(): void
    {
        $html = $this->render([
            ['id' => 'p1', 'label' => 'Écrit', 'text' => 'Du texte.'],
            ['id' => 'p2', 'label' => '', 'text' => ''],
        ]);

        self::assertSame(1, mb_substr_count($html, 'data-tabs-panel'));
    }

    /** The editor keeps it: a panel being written has to stay editable. */
    public function testTheEditorKeepsAnEmptyPanel(): void
    {
        $grid = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => [[
                'id' => 'z1',
                'type' => 'tabs',
                'items' => [['id' => 'p1'], ['id' => 'p2']],
            ]]],
            ['zones' => ['z1' => ['items' => ['p1' => ['title' => 'Écrit']]]]],
            'fr',
        );

        self::assertCount(2, $grid['zones'][0]['tabs']['panels']);
    }

    /** Six is the cap: past that the strip of labels stops reading as a row. */
    public function testThePanelsAreCapped(): void
    {
        $panels = [];
        for ($i = 1; $i <= 9; ++$i) {
            $panels[] = ['id' => 'p'.$i, 'label' => 'Onglet '.$i, 'text' => 'Texte.'];
        }

        self::assertSame(6, mb_substr_count($this->render($panels), 'data-tabs-panel'));
    }

    /**
     * @param list<array{id: string, label: string, text: string}> $panels
     */
    private function render(array $panels): string
    {
        $items = [];
        $words = [];

        foreach ($panels as $panel) {
            $items[] = ['id' => $panel['id']];
            $words[$panel['id']] = [
                'title' => $panel['label'],
                'blocks' => '' === $panel['text']
                    ? []
                    : [['type' => 'paragraph', 'data' => ['text' => $panel['text']]]],
            ];
        }

        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'tabs', 'items' => $items]]],
            ['zones' => ['z1' => ['items' => $words]]],
            'fr',
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => 'fr'],
        );
    }
}
