<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\View;

use Aurora\Module\General\Dashboard\Service\StatsService;

/**
 * Builds the Twig payload for the dev overview tab. Wraps StatsService
 * so the controller stays focused on flow (XHR vs full page rendering).
 */
final readonly class OverviewViewBuilder
{
    public function __construct(private StatsService $statsService) {}

    /**
     * @return array<string, mixed>
     */
    public function overviewPayload(): array
    {
        // Module ids whose figures the dev overview wants. Empty while no
        // module ships a DashboardStatsProviderInterface.
        return ['stats' => $this->statsService->getStats([])];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function indexView(array $payload): array
    {
        return [
            'tab' => 'overview',
            'stats' => $payload['stats'],
        ];
    }
}
