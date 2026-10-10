<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Nav;

use Aurora\Core\Module\Nav\NavItemCountProviderInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;

/**
 * The side menu's figure for the library: its documents, out of the trash -
 * the same total as the GED tile of the dashboard, from the same query.
 */
final readonly class GedNavItemCountProvider implements NavItemCountProviderInterface
{
    private const string DOCUMENTS = 'suite_ged_documents';

    public function __construct(
        private DocumentRepository $documentRepository,
    ) {}

    public function getCountedItemKeys(): array
    {
        return [self::DOCUMENTS];
    }

    public function countItem(string $itemKey): int
    {
        return self::DOCUMENTS === $itemKey ? array_sum($this->documentRepository->countGroupedByMimeType()) : 0;
    }
}
