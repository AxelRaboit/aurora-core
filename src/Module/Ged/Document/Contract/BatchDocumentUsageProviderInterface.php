<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Contract;

/**
 * Answers for a page of documents at once, rather than one at a time.
 *
 * {@see DocumentUsageProviderInterface} is asked about a single document,
 * which is the right shape for the deletion screen: somebody clicked one
 * file and wants the list of things that would break. It is the wrong shape
 * for the library's own listing, where fifty rows each want to know whether
 * anything is drawing them. Asked one at a time, that page costs fifty
 * lookups per provider, and the providers that walk their source - decks,
 * posts, space notes - would walk it fifty times over.
 *
 * So a provider that can answer in bulk declares it here, and the listing
 * asks once. The walk happens a single time and is tallied against the ids
 * it was given; the relations become one grouped query instead of fifty.
 *
 * **Optional on purpose.** The tag accepts providers that implement only the
 * single-document interface, and {@see DocumentUsageService} falls back to
 * calling them in a loop. That keeps a module outside this bundle working
 * without changes, at a cost it can remove whenever it wants by implementing
 * this as well.
 *
 * A count and not a list, because the listing draws a badge. Anything that
 * needs to name the sources opens one document, and that is the other
 * interface.
 */
interface BatchDocumentUsageProviderInterface extends DocumentUsageProviderInterface
{
    /**
     * How many sources in this module point at each of these documents.
     *
     * The answer holds only the documents this provider knows something
     * about: an id that nothing here draws is absent rather than present with
     * a zero, so a caller merging several providers can `+=` without first
     * filtering.
     *
     * The count matches what {@see DocumentUsageProviderInterface::findUsages()}
     * would return for that id, item for item - a source drawing the same
     * picture twice counts once, because that is one thing to open.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesFor(array $documentIds): array;
}
