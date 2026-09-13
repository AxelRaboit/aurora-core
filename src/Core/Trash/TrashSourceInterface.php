<?php

declare(strict_types=1);

namespace Aurora\Core\Trash;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;

/**
 * A trash a module owns, offered whole to the trash screen.
 *
 * Lives in core for the same reason {@see DashboardStatsProviderInterface}
 * does: the General shell must never import a business module's repositories.
 * Each module ships its own source, auto-registered through the
 * `aurora.trash_source` tag, and a module that is absent simply contributes
 * nothing.
 *
 * A source answers for the user who is looking, and that is not a detail:
 * publications are scoped to their author for whoever is neither developer nor
 * administrator, and notes belong to their author outright. A source that
 * ignored this would turn one screen into the log of what everybody else
 * deleted.
 */
interface TrashSourceInterface
{
    /**
     * Module id gating this source, matched against the modules the screen
     * reports enabled (e.g. 'ged', 'notes', 'editorial').
     */
    public function getModuleKey(): string;

    /**
     * The privilege that opens this trash, null when it needs none.
     *
     * A source the reader may not open is dropped entirely: a count of things
     * they were never shown is still something about them.
     */
    public function getRequiredPrivilege(): ?string;

    /**
     * @param int $limit how many rows to return at most; `count` on the
     *                   summary stays the real total
     */
    public function getSummary(int $limit): TrashSummary;
}
