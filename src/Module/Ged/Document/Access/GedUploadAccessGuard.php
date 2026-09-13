<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Access;

use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Access\UploadAccessGuardInterface;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;

use function str_starts_with;

/**
 * Who may read a file in the GED through the public catch-all.
 *
 * The rule is the one the entity already carried and nobody asked: a
 * document is readable without a session when it is `published`, and not
 * before. `draft` is the default status of an upload
 * (`useDocumentsForm.js`), which is the whole point - a contract or an
 * identity document that has just been filed is withheld until somebody
 * says otherwise, rather than exposed until somebody notices. `archived` is
 * withheld too, which matches the public library: it has always listed
 * `published` alone.
 *
 * **Two answers only, and never `Restricted`.** That is not an oversight, it
 * is the firewall: `/uploads/{path}` is not matched by
 * `^/(backend|dev)`, so a backend session is never restored on one of these
 * requests and asking whether the visitor holds `ged.documents.view` would
 * be asking a question whose answer is always no. Staff read a withheld file
 * through `backend_ged_files` instead, which is under the prefix the
 * firewall does cover - the arrangement CLAUDE.md §5bis prescribes, and the
 * one the contracts module already uses.
 */
final readonly class GedUploadAccessGuard implements UploadAccessGuardInterface
{
    public function __construct(
        private DocumentRepository $documentRepository,
    ) {}

    public function supports(string $key): bool
    {
        return str_starts_with($key, StorageAreaEnum::Ged->value.'/');
    }

    public function decide(string $key): UploadAccessEnum
    {
        // Covers everything that is not a published document, on purpose: a
        // draft, an archived one, one waiting in the bin, and a key no living
        // row claims at all - an orphan, or the file a previous version
        // snapshotted. None of those is something a public page embeds.
        return DocumentStatusEnum::Published === $this->documentRepository->findStatusForPath($key)
            ? UploadAccessEnum::Anonymous
            : UploadAccessEnum::Denied;
    }
}
