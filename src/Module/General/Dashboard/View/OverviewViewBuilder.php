<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\View;

/**
 * Builds the Twig payload for the dev overview tab. Reuses the dashboard's
 * own view so the two never disagree on which modules count, and keeps the
 * controller focused on flow (XHR vs full page rendering).
 */
final readonly class OverviewViewBuilder
{
    public function __construct(private DashboardViewBuilder $dashboardViewBuilder) {}

    /**
     * @return array<string, mixed>
     */
    public function overviewPayload(): array
    {
        // The same figures as the back-office dashboard, for the modules that
        // are on. It asked for none at all, from the days no module provided
        // any, and the tab always said there was nothing to show.
        return $this->dashboardViewBuilder->indexView();
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
            'enabledModules' => $payload['enabledModules'],
        ];
    }
}
