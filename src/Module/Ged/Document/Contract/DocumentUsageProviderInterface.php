<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Contract;

/**
 * Implemented by every module that points at a GED document, so the library
 * can answer "who is using this" before somebody deletes it.
 *
 * **A module that references a document and provides nothing here makes the
 * deletion screen lie**, and the screen is the only warning there is. What
 * follows the deletion depends on how the pointer was declared, and neither
 * outcome is visible: a `CASCADE` relation takes its own row with it - a
 * space attachment simply vanishes from the client's space - while a
 * `SET NULL` one, or an id buried in a JSON column, leaves a post or a slide
 * quietly drawing nothing.
 *
 * Two shapes, because there are two ways to hold a document. Where the
 * pointer is a Doctrine relation the query is exact and survives a rename
 * ({@see SpaceAttachmentDocumentUsageProvider}); where the id lives inside a
 * JSON column it has to be scanned for, which is looser and wants a
 * narrowing clause first on anything that grows ({@see PostDocumentUsageProvider}).
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
