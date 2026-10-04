<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

/**
 * What a code zone draws around the snippet.
 *
 * The snippet itself is the highlighter's business and runs in the browser;
 * what the server owns is the frame - the gutter of numbers and the copy
 * button - and both are the kind of thing that looks right in a screenshot and
 * is wrong by one line.
 */
final class GridCodeZoneTest extends IntegrationTestCase
{
    private const string SNIPPET = "un();\ndeux();\ntrois();";

    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->gridViewBuilder = self::getContainer()->get(GridViewBuilder::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testTheGutterHasOneNumberPerLine(): void
    {
        $html = $this->render(['lineNumbers' => true]);

        self::assertMatchesRegularExpression('/aria-hidden="true"\s*>1\n2\n3</', $html);
    }

    /** Off by default: a three-line example needs no coordinates. */
    public function testASnippetIsNotNumberedUnlessItAsksToBe(): void
    {
        self::assertStringNotContainsString('select-none', $this->render([]));
    }

    /**
     * Rendered hidden, and revealed by the script once it has found a
     * clipboard: a button that cannot do its job should not be offered.
     */
    public function testTheCopyButtonWaitsForItsScript(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('data-code-copy="Copier"', $html);
        self::assertStringContainsString('data-code-copied="Copié"', $html);
        self::assertMatchesRegularExpression('/<button[^>]*\shidden/', $html);
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides): string
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'code', 'language' => 'php', ...$overrides]],
            ],
            ['zones' => ['z1' => ['code' => self::SNIPPET]]],
            'fr',
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => 'fr'],
        );
    }
}
