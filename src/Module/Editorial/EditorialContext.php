<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Reads Editorial's module toggles. Everything in the module asks this
 * rather than {@see ModuleAccessChecker} directly, so a toggle is named
 * once and the callers read as intent ("are posts enabled?") instead of
 * as a settings lookup.
 *
 * One method per toggle; they arrive as the sub-modules they gate do.
 */
final readonly class EditorialContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isBackendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::EditorialBackend);
    }

    public function isPostsEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::EditorialPosts);
    }

    public function isPostTypesEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::EditorialPostTypes);
    }

    public function isTaxonomiesEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::EditorialTaxonomies);
    }

    public function isMenusEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::EditorialMenus);
    }

    public function isSeoEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::EditorialSeo);
    }
}
