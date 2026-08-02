<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\View;

use Aurora\Module\General\Dashboard\Service\StatsService;

/**
 * Builds the Twig payload for the backend dashboard.
 *
 * No module draws a dashboard panel at the moment, so both keys come back
 * empty and the Vue shell renders its empty state. A module joins by
 * gating on its own backend toggle here — a plain core_settings key, so
 * this shell never imports a business module's parameter enum — and
 * adding a matching entry to the Vue `MODULE_DEFINITIONS`. Its figures
 * reach `stats` through a DashboardStatsProviderInterface, which
 * {@see StatsService} filters by the module ids passed below.
 */
final readonly class DashboardViewBuilder
{
    public function __construct(
        private StatsService $statsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        /** @var array<string, bool> $enabledModules */
        $enabledModules = [];

        return [
            'enabledModules' => $enabledModules,
            'stats' => $this->statsService->getStats(array_keys(array_filter($enabledModules))),
        ];
    }
}
