<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use Aurora\Core\Frontend\Contract\FrontendInterface;

final class {{MODULE}}FrontendDescriptor implements FrontendInterface
{
    /**
     * Client-side frontend descriptor. The `FRONTEND_KEY` constant must
     * exist on {{MODULE}}Context - add it when you run aurora:make:module
     * with both --with-toggles AND --with-frontend, or define it manually.
     */
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
        return {{MODULE}}Context::FRONTEND_KEY;
    }

    public function getRoutePrefixes(): array
    {
        return ['frontend_{{MODULE_ID}}_'];
    }
}
