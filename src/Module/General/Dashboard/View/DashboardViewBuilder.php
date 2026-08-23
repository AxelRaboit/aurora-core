<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\View;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\General\Dashboard\Service\StatsService;

/**
 * Builds the Twig payload for the backend dashboard.
 *
 * A module joins by adding a line to MODULE_TOGGLES below and a
 * `*-panel.register.js` on the Vue side, which fills the panel registry before
 * any app mounts. (That register file replaced a `MODULE_DEFINITIONS` constant
 * this comment still named.) Its figures reach `stats` through a
 * DashboardStatsProviderInterface, which {@see StatsService} filters by the
 * module ids reported enabled here. With nothing listed, the Vue shell draws
 * its empty state.
 *
 * The toggle is named as a plain settings key so this shell never imports a
 * business module's parameter enum, and passed as a string to
 * {@see ModuleAccessChecker}, which accepts one - so the cascade and the
 * per-user masking still apply, and the dashboard cannot disagree with the
 * side menu about what is switched on.
 */
final readonly class DashboardViewBuilder
{
    /**
     * Module id → the settings key gating it. The id is what a stats provider
     * returns from `getModuleKey()` and what the Vue definitions match on.
     *
     * @var array<string, string>
     */
    private const array MODULE_TOGGLES = [
        'editorial' => 'modules_editorial_backend',
        'ged' => 'modules_ged_backend',
        'platform' => 'modules_platform_backend',
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
        $enabledModules = [];
        foreach (self::MODULE_TOGGLES as $moduleId => $toggle) {
            $enabledModules[$moduleId] = $this->moduleAccessChecker->isEnabled($toggle);
        }

        return [
            'enabledModules' => $enabledModules,
            'stats' => $this->statsService->getStats(array_keys(array_filter($enabledModules))),
        ];
    }
}
