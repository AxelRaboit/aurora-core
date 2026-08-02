<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Enum;

/**
 * What a menu entry points at. Kept as a type rather than a stored URL so
 * a renamed post carries its menu entry along instead of leaving a dead
 * link behind.
 */
enum MenuItemTargetTypeEnum: string
{
    case Post = 'post';
    case Term = 'term';
    case PostTypeArchive = 'post_type_archive';
    case Home = 'home';
    case CustomUrl = 'custom_url';
    case FrontLogin = 'frontend_login';
    case FrontRegister = 'frontend_register';
    case FrontAccount = 'frontend_account';
    case FrontLogout = 'frontend_logout';

    public function labelKey(): string
    {
        return sprintf('backend.menus.target_types.%s', $this->value);
    }

    public function requiresTargetId(): bool
    {
        return match ($this) {
            self::Post, self::Term, self::PostTypeArchive => true,
            default => false,
        };
    }

    public function requiresCustomUrl(): bool
    {
        return self::CustomUrl === $this;
    }

    /** Entries that only make sense once accounts exist on the front. */
    public function isAccountLink(): bool
    {
        return match ($this) {
            self::FrontLogin, self::FrontRegister, self::FrontAccount, self::FrontLogout => true,
            default => false,
        };
    }
}
