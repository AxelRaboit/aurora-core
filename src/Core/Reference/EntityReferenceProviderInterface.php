<?php

declare(strict_types=1);

namespace Aurora\Core\Reference;

/**
 * Resolves a soft cross-module entity reference (a bare id) to a display-ready
 * summary. Lives in core so a module holding a soft reference (e.g. Photo's
 * gallery → CRM contact) never imports the owning module: it asks the core
 * {@see EntityReferenceResolver}, which delegates to the provider the owning
 * module registers via the `aurora.entity_reference_provider` tag. If that
 * module isn't installed, no provider answers and the reference resolves to
 * null - the holder degrades gracefully.
 */
interface EntityReferenceProviderInterface
{
    /** Reference type key this provider handles, e.g. 'crm.contact'. */
    public function getType(): string;

    /**
     * Display-ready summary of the referenced entity, or null if it no longer
     * exists. Shape is owned by the provider (typically id + label + a few
     * fields the consumer splices into its own output).
     *
     * @return array<string, mixed>|null
     */
    public function summarize(int $id): ?array;

    /**
     * All selectable options of this type for a picker, ordered for display.
     *
     * @return list<array{id: int, name: string}>
     */
    public function options(): array;
}
