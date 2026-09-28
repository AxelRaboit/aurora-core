<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Contract;

use Aurora\Module\Ged\Document\Service\DocumentUsageService;

/**
 * A usage provider that says, up front, which kind of source it reports.
 *
 * The batch count is a number per document and nothing more, which is enough
 * for an "Inutilisé" badge but not for a family: to say that the green copy
 * is on two pages and the red one in a deck, the listing needs each count
 * sorted by kind of source. A provider answering for a single kind declares
 * it here, and {@see DocumentUsageService}
 * files its batch counts under it.
 *
 * Optional, like the batch interface: a provider that does not declare it is
 * counted under "other" in the batch path, or by the `type` of each item it
 * returns when it is called one document at a time.
 */
interface TypedDocumentUsageProviderInterface extends DocumentUsageProviderInterface
{
    /** The `type` every item of {@see self::findUsages()} carries. */
    public function usageType(): string;
}
