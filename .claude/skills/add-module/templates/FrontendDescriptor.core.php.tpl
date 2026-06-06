<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use Aurora\Core\Frontend\Contract\FrontendInterface;
use {{NAMESPACE}}\Setting\{{MODULE}}ModuleParameterEnum;

final class {{MODULE}}FrontendDescriptor implements FrontendInterface
{
    public function getSlug(): string
    {
        return '{{MODULE_ID}}';
    }

    public function getLabel(): string
    {
        return '{{MODULE_LABEL}}';
    }

    public function getHomeRoute(): string
    {
        return 'frontend_{{MODULE_ID}}';
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function getModuleSettingKey(): string
    {
        return {{MODULE}}ModuleParameterEnum::Frontend->value;
    }

    public function getRoutePrefixes(): array
    {
        return ['frontend_{{MODULE_ID}}_'];
    }
}
