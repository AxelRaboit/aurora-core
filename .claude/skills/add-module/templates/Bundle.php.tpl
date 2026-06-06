<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use Aurora\Core\Bundle\AbstractAuroraModuleBundle;

/** Self-contained bundle for the {{MODULE_LABEL}} module. @see AbstractAuroraModuleBundle */
final class Aurora{{MODULE}}Bundle extends AbstractAuroraModuleBundle
{
    protected function moduleName(): string
    {
        return '{{MODULE}}';
    }

    /**
     * Entity interface → concrete class map for this module. Empty until
     * `/add-entity` adds entities — each new entity appends one line here
     * (this REPLACES the central `AuroraBundle::$resolve_target_entities`
     * for a packaged module).
     *
     * @return array<class-string, class-string>
     */
    protected function resolveTargetEntities(): array
    {
        return [];
    }
}
