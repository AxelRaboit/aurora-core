<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Contract;

/**
 * Lets a module declare the menu locations its theme renders, and what a
 * fresh install should find in them. A location nobody declares is one no
 * template asks for - and one the backend will not offer to fill.
 */
interface MenuLocationProviderInterface
{
    /** @return list<MenuLocation> */
    public function getMenuLocations(): array;
}
