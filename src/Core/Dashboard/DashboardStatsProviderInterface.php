<?php

declare(strict_types=1);

namespace Aurora\Core\Dashboard;

/**
 * A module-contributed slice of the backend dashboard statistics.
 *
 * Lives in core so the General dashboard aggregator never imports a business
 * module's repositories: each module ships its own provider (e.g.
 * `Module\Crm\Dashboard\CrmStatsProvider`), auto-registered via the
 * `aurora.dashboard_stats_provider` tag. With no module installed, no provider
 * contributes and the dashboard simply shows less.
 */
interface DashboardStatsProviderInterface
{
    /**
     * Module key gating this provider - must match the entries the dashboard
     * passes as "enabled modules" (e.g. 'editorial', 'crm', 'billing').
     */
    public function getModuleKey(): string;

    /**
     * Stat fragment merged (by string key) into the dashboard payload. Keys
     * are owned by the provider (e.g. Crm contributes `{crm: …}`, Editorial
     * contributes `{posts: …, comments: …, …}`).
     *
     * @return array<string, mixed>
     */
    public function getStats(): array;
}
