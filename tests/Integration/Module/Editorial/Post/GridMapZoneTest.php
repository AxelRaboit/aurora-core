<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

/**
 * The zone that says where somebody is without asking a map provider.
 *
 * The whole design of it is what does *not* happen while the page is read:
 * nothing is fetched from anybody, the address is text the author typed, and
 * the link is followed only if the reader decides to. So the test that earns
 * its place is the one asserting no provider appears in a `src`.
 */
final class GridMapZoneTest extends IntegrationTestCase
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

    public function testItDrawsTheAddressAsAnAddress(): void
    {
        $html = $this->render('Notre atelier', "12 rue des Lilas\n69004 Lyon\nFrance");

        self::assertStringContainsString('<address', $html);
        self::assertStringContainsString('Notre atelier', $html);
        self::assertStringContainsString('12 rue des Lilas', $html);
        // One line per line, without asking the author for markup.
        self::assertSame(2, mb_substr_count($html, '<br>'));
    }

    /**
     * The point of the zone. A draggable map would be a third party on every
     * view, chosen once by us on behalf of every client; a link is a third
     * party only for the reader who asks to be taken there.
     */
    public function testNothingIsLoadedFromAMapProviderWhileReading(): void
    {
        $html = $this->render('Notre atelier', '12 rue des Lilas, Lyon');

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<script', $html);
        // No `src` at all: the only picture a map zone can draw is one the
        // author put in the library themselves, and this one has none.
        self::assertStringNotContainsString('src=', $html);
    }

    public function testTheDirectionsLinkCarriesTheWholeAddress(): void
    {
        $html = $this->render('', "12 rue des Lilas\n69004 Lyon");

        self::assertStringContainsString(
            'query=12%20rue%20des%20Lilas%2C%2069004%20Lyon',
            $html,
        );
    }

    /**
     * A zone with a name and a photograph but no address draws nothing: it
     * cannot answer the one question it exists for.
     */
    public function testAZoneWithNoAddressDrawsNothing(): void
    {
        self::assertSame('', mb_trim(strip_tags($this->render('Notre atelier', ''))));
    }

    /** Blank lines an author leaves while typing are not lines of an address. */
    public function testBlankLinesAreNotAddressLines(): void
    {
        $html = $this->render('', "12 rue des Lilas\n\n\n69004 Lyon\n");

        self::assertSame(1, mb_substr_count($html, '<br>'));
    }

    private function render(string $name, string $address): string
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'map']]],
            ['zones' => ['z1' => ['label' => $name, 'caption' => $address]]],
            'fr',
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => 'fr'],
        );
    }
}
