<?php

declare(strict_types=1);

namespace Aurora\Core\Search;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;

/**
 * Module-contributed search adapter aggregated by the Assistant's
 * `aurora_search` tool (and, eventually, the backend global search
 * controller). Decouples the Assistant module from every domain
 * module's repositories - without this, the tool would have to import
 * Editorial, Project, Media, etc. directly, violating Aurora's
 * "no cross-module dep in src/Module/<X>/" rule.
 *
 * Implementations live in the module that owns the searched entity
 * (e.g. `Module\Editorial\Search\PostSearchProvider`) and are auto-
 * registered via the `aurora.search_provider` service tag.
 */
interface SearchProviderInterface
{
    /**
     * Run the search and return at most `$limit` formatted result rows.
     * Each row is a short single line ready to be spliced into the
     * LLM's next prompt - pre-formatted because each provider is in
     * the best position to know which fields are meaningful (status,
     * reference, agency, …).
     *
     * Implementations MUST scope results to `$user` when applicable
     * (e.g. user's tasks only) and MUST swallow internal errors -
     * never throw out of `search()`.
     *
     * @return list<string>
     */
    public function search(string $query, int $limit, CoreUserInterface $user): array;
}
