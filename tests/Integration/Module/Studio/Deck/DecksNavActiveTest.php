<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Core\Module\Nav\NavItem;
use Aurora\Module\Studio\StudioModule;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Routing\RouterInterface;

use function str_starts_with;

/**
 * The side menu lights « Présentations » on every page of a deck.
 *
 * The menu lights the entry whose route name the current one starts with. A
 * deck's own pages are named `backend_studio_deck…`, singular, so opening a
 * presentation lit nothing: the list's `backend_studio_decks` is not a prefix
 * of the editor's name.
 */
final class DecksNavActiveTest extends IntegrationTestCase
{
    public function testTheDecksEntryCoversTheListAndEveryPageOfADeck(): void
    {
        static::bootKernel();
        $container = static::getContainer();

        $entry = null;
        foreach ($container->get(StudioModule::class)->getCatalogNavSections() as $section) {
            foreach ($section->items as $item) {
                if ('backend_studio_decks' === $item->route) {
                    $entry = $item;
                }
            }
        }

        self::assertInstanceOf(NavItem::class, $entry);
        $lit = $entry->activeRoutePrefix ?? $entry->route;

        $routes = $container->get(RouterInterface::class)->getRouteCollection();
        foreach (['backend_studio_decks', 'backend_studio_deck', 'backend_studio_deck_presenter', 'backend_studio_deck_print'] as $page) {
            self::assertNotNull($routes->get($page), $page.' is a route');
            self::assertTrue(str_starts_with($page, $lit), $page.' lights the decks entry');
        }
    }
}
