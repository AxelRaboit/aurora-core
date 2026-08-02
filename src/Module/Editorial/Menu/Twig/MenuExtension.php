<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Twig;

use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Menu\Service\MenuLocationRegistry;
use Aurora\Module\Editorial\Menu\Service\MenuRenderer;
use Twig\Attribute\AsTwigFunction;

/**
 * `menu_items('primary', locale)` — what a theme calls to render navigation.
 *
 * An empty list is the answer to every "no": menus switched off, a location
 * nobody declared, no menu bound there, nothing in it visible to whoever is
 * looking. Templates then guard on emptiness alone and never on whether the
 * module happens to be installed.
 */
final readonly class MenuExtension
{
    public function __construct(
        private MenuRenderer $menuRenderer,
        private MenuLocationRegistry $locationRegistry,
        private EditorialContext $editorialContext,
    ) {}

    /** @return array<int, array<string, mixed>> */
    #[AsTwigFunction(name: 'menu_items')]
    public function menuItems(string $location, string $locale): array
    {
        if (!$this->editorialContext->isMenusEnabled()) {
            return [];
        }

        if (!$this->locationRegistry->has($location)) {
            return [];
        }

        return $this->menuRenderer->render($location, $locale);
    }
}
