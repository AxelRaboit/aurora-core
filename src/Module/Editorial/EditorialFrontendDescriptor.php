<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial;

use Aurora\Core\Frontend\Contract\FrontendInterface;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Declares Editorial as a public front. The highest-priority enabled front
 * is what `/` resolves to, so a site with Editorial on lands on its content
 * rather than on the document library.
 */
final class EditorialFrontendDescriptor implements FrontendInterface
{
    public function getSlug(): string
    {
        return 'editorial';
    }

    public function getLabel(): string
    {
        return 'Editorial';
    }

    public function getHomeRoute(): string
    {
        return 'editorial_home';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getModuleSettingKey(): string
    {
        return ModuleParameterEnum::EditorialFrontend->value;
    }

    public function getRoutePrefixes(): array
    {
        return ['editorial_'];
    }
}
