<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Editorial — the content module: post types, taxonomies, posts, menus.
 *
 * Being rebuilt one sub-domain at a time, so the nav is deliberately
 * empty for now: a screen appears here in the same commit that brings
 * its controller and its toggle, never before. The module still
 * registers, which is what puts its backend toggle in the module
 * catalogue.
 */
final readonly class EditorialModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private EditorialContext $editorialContext) {}

    public function getId(): string
    {
        return 'editorial';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getNavSections(): array
    {
        if (!$this->editorialContext->isBackendEnabled()) {
            return [];
        }

        return [];
    }

    public function getCatalogNavSections(): array
    {
        return [];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::EditorialBackend->toToggle(),
        ];
    }
}
