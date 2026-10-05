<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Search;

use Aurora\Core\Search\SuiteSearchProviderInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\GedContext;
use Symfony\Bundle\SecurityBundle\Security;
use Throwable;

use function array_map;

/**
 * Ged (media library) slice of the suite global search: documents by name.
 * Ged ships in core, but contributing through the same provider registry keeps
 * the General search controller a pure aggregator with no domain knowledge.
 *
 * Guarded like its two neighbours, which it was not for as long as it existed.
 * The aggregator only asks for `general.search.view`, so an account that could
 * open the search box got file names back from a library it has no privilege
 * on - and kept getting them on a deployment where the module was switched
 * off entirely. Editorial refuses when its module is off and scopes to what
 * the account may manage; Planning scopes to the calendars it may see; this
 * one answered everybody.
 */
final readonly class GedSuiteSearchProvider implements SuiteSearchProviderInterface
{
    /**
     * The same privilege the documents screen is behind.
     *
     * Deliberately that one and not a search-specific privilege: a result the
     * list refuses to show is a leak, and the surprise lands on whoever clicks
     * it and gets a 403.
     */
    private const string PRIVILEGE = 'ged.documents.view';

    private const int LIMIT = 10;

    public function __construct(
        private DocumentRepository $documentRepository,
        private GedContext $gedContext,
        private Security $security,
    ) {}

    public function search(string $query): array
    {
        if (!$this->gedContext->isDocumentsEnabled() || !$this->security->isGranted(self::PRIVILEGE)) {
            return [];
        }

        // The contract says never throw: one module's search failing must not
        // take the whole search box down with it.
        try {
            return [
                'media' => array_map(
                    static fn (DocumentInterface $document): array => [
                        'id' => $document->getId(),
                        'name' => $document->getOriginalName() ?? $document->getTitle(),
                        'mimeType' => $document->getMimeType(),
                        'alt' => $document->getAlt(),
                    ],
                    $this->documentRepository->searchByName($query, self::LIMIT),
                ),
            ];
        } catch (Throwable) {
            return [];
        }
    }
}
