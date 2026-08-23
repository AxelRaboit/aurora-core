<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\Service;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Aggregates the backend dashboard statistics from every module-contributed
 * {@see DashboardStatsProviderInterface}. The General shell owns no domain
 * knowledge here - each module ships its own provider, so this service (and
 * the whole General module) never imports a business module's repositories.
 */
final readonly class StatsService
{
    /**
     * @param iterable<DashboardStatsProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('aurora.dashboard_stats_provider')]
        private iterable $providers,
    ) {}

    /**
     * @param list<string> $enabledModules Module IDs to include, matched against each
     *                                     provider's getModuleKey(). An empty list
     *                                     returns an empty array.
     *
     * @return array<string, mixed>
     */
    public function getStats(array $enabledModules): array
    {
        $stats = [];
        foreach ($this->providers as $provider) {
            if (!in_array($provider->getModuleKey(), $enabledModules, true)) {
                continue;
            }

            $stats = [...$stats, ...$provider->getStats()];
        }

        return $stats;
    }
}
