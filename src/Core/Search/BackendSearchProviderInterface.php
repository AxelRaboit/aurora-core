<?php

declare(strict_types=1);

namespace Aurora\Core\Search;

/**
 * A module-contributed slice of the backend global search (the sidemenu search
 * box). Lives in core so the General search controller never imports a business
 * module's repositories: each module ships its own provider (e.g.
 * `Module\Editorial\Search\EditorialBackendSearchProvider`), auto-registered via
 * the `aurora.backend_search_provider` tag. A module that is absent simply
 * contributes no section.
 */
interface BackendSearchProviderInterface
{
    /**
     * Run the search and return one or more named result sections. Each section
     * key (e.g. 'posts', 'projects', 'media') maps to a list of already-
     * serialized result rows ready for the frontend. Return an empty array when
     * nothing matches. MUST swallow internal errors - never throw.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function search(string $query): array;
}
