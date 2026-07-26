<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\View;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\General\Dashboard\Service\StatsService;

final readonly class DashboardViewBuilder
{
    /**
     * Module id → its backend toggle key. Plain strings (not a typed enum) so
     * the General shell stays decoupled from every business module's own
     * parameter enum. The keys are stable core_settings keys.
     *
     * @var array<string, string>
     */
    private const array DASHBOARD_MODULES = [
        'editorial' => 'modules_editorial_backend',
    ];

    public function __construct(
        private StatsService $statsService,
        private ModuleAccessChecker $moduleAccessChecker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        $enabledModules = $this->buildEnabledModules();
        $enabledModuleIds = array_keys(array_filter($enabledModules));

        return [
            'enabledModules' => $enabledModules,
            'stats' => $this->statsService->getStats($enabledModuleIds),
        ];
    }

    /** @return array<string, bool> */
    private function buildEnabledModules(): array
    {
        $result = [];
        foreach (self::DASHBOARD_MODULES as $moduleId => $toggle) {
            $result[$moduleId] = $this->moduleAccessChecker->isEnabled($toggle);
        }

        return $result;
    }
}
