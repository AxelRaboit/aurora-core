<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Enum;

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
}
