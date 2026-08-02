<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Enum;

enum MenuItemVisibilityEnum: string
{
    case Always = 'always';
    case GuestsOnly = 'guests_only';
    case AuthenticatedOnly = 'authenticated_only';

    /**
     * Never rendered, whoever is looking. Lets an entry be parked without
     * deleting it — deletion takes the children with it, so "hide this
     * branch for now" had no safe expression otherwise.
     */
    case Hidden = 'hidden';

    public function labelKey(): string
    {
        return sprintf('backend.menus.visibilities.%s', $this->value);
    }

    /** @param bool $authenticated whether someone is signed in on the front */
    public function isVisibleTo(bool $authenticated): bool
    {
        return match ($this) {
            self::Always => true,
            self::GuestsOnly => !$authenticated,
            self::AuthenticatedOnly => $authenticated,
            self::Hidden => false,
        };
    }
}
