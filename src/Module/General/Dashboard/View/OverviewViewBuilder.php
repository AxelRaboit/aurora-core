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
        return ['stats' => $this->statsService->getStats(['editorial'])];
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
