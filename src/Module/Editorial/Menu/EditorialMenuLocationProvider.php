<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu;

use Aurora\Module\Editorial\Menu\Contract\DefaultMenuItem;
use Aurora\Module\Editorial\Menu\Contract\MenuLocation;
use Aurora\Module\Editorial\Menu\Contract\MenuLocationProviderInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;

/**
 * The three slots the default theme renders. A client theme is free to
 * declare its own and ignore these.
 */
final readonly class EditorialMenuLocationProvider implements MenuLocationProviderInterface
{
    public const string PRIMARY = 'primary';

    public const string FOOTER = 'footer';

    public const string ACCOUNT = 'account';

    public function getMenuLocations(): array
    {
        return [
            new MenuLocation(
                self::PRIMARY,
                'suite.menus.locations.primary',
                'suite.menus.locations.primary_description',
                [
                    new DefaultMenuItem('editorial.primary.home', 'suite.menus.defaults.home', MenuItemTargetTypeEnum::Home),
                ],
            ),
            new MenuLocation(
                self::FOOTER,
                'suite.menus.locations.footer',
                'suite.menus.locations.footer_description',
            ),
            // Seeded with the sign-in pair rather than left empty: an account
            // menu with nothing in it renders as nothing, and the first thing
            // anyone adds here is these two anyway. Each is visible to exactly
            // the side of the sign-in line that can use it.
            new MenuLocation(
                self::ACCOUNT,
                'suite.menus.locations.account',
                'suite.menus.locations.account_description',
                [
                    new DefaultMenuItem(
                        'editorial.account.login',
                        'suite.menus.defaults.login',
                        MenuItemTargetTypeEnum::FrontLogin,
                        visibility: MenuItemVisibilityEnum::GuestsOnly,
                    ),
                    new DefaultMenuItem(
                        'editorial.account.logout',
                        'suite.menus.defaults.logout',
                        MenuItemTargetTypeEnum::FrontLogout,
                        visibility: MenuItemVisibilityEnum::AuthenticatedOnly,
                    ),
                ],
            ),
        ];
    }
}
