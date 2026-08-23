<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Contract;

/**
 * Implemented by modules that reference a GED Document (Billing invoices &
 * OCR jobs, Project task attachments, client modules like Welding…). The
 * aggregator service iterates all tagged providers to surface where a
 * document is used - before deletion or just for traceability.
 *
 * Unlike the Media library (referenced by id embedded in free-form content),
 * documents are referenced through explicit Doctrine FK relations, so each
 * provider is an exact, refactor-safe query - no content scanning.
 *
 * Tag implementing classes with `aurora.document_usage_provider`
 * (autoconfigured via the interface alias).
 */
interface DocumentUsageProviderInterface
{
    /**
     * @return list<array{type: string, label: string, detail?: ?string, href?: ?string}>
     *
     *  - type   : machine identifier of the source (e.g. "billing.invoice", "project.task")
     *  - label  : human-readable target name (invoice number, task title…)
     *  - detail : optional secondary description ("Facture", "Tâche projet"…)
     *  - href   : optional admin URL to navigate to the source
     */
    public function findUsages(int $documentId): array;
}
