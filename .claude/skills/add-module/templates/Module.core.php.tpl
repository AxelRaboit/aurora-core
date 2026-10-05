<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

final readonly class {{MODULE}}Module implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private {{MODULE}}Context ${{MODULE_VAR}}Context) {}

    public function getId(): string
    {
        return '{{MODULE_ID}}';
    }

    public function getPermissions(): array
    {
        return [new NavPermission('{{MODULE_ID}}.use')];
    }

    public function getNavSections(): array
    {
        if (!$this->{{MODULE_VAR}}Context->isSuiteEnabled()) {
            return [];
        }

        return [
            new NavSection('{{MODULE_ID}}', [
                new NavItem(
                    'suite_{{MODULE_ID}}',
                    'suite.nav.{{MODULE_ID}}',
                    '{{ICON}}',
                    requiredPrivilege: '{{MODULE_ID}}.use',
                    descriptionKey: 'suite.nav.{{MODULE_ID}}_description',
                ),
            ], priority: {{PRIORITY}}),
        ];
    }

    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('{{MODULE_ID}}', [
                new NavItem(
                    'suite_{{MODULE_ID}}',
                    'suite.nav.{{MODULE_ID}}',
                    '{{ICON}}',
                    requiredPrivilege: '{{MODULE_ID}}.use',
                    descriptionKey: 'suite.nav.{{MODULE_ID}}_description',
                ),
            ], priority: {{PRIORITY}}),
        ];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::{{MODULE}}Suite->toToggle(),
        ];
    }
}
