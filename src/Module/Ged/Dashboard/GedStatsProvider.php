<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\DocumentTag\Repository\DocumentTagRepository;

/**
 * The GED's figures on the backend dashboard.
 *
 * The library held four counting methods nobody called: the dashboard has
 * shown Editorial and nothing else since it was built, because Editorial was
 * the only module that ever shipped a provider. This is the same arrangement,
 * one file, no query written.
 *
 * Like Editorial's, unscoped by owner: this answers "what does the library
 * hold", which is a question about the library.
 */
final readonly class GedStatsProvider implements DashboardStatsProviderInterface
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentCategoryRepository $categoryRepository,
        private DocumentTagRepository $tagRepository,
        private DocumentFolderRepository $folderRepository,
    ) {}

    public function getModuleKey(): string
    {
        return 'ged';
    }

    public function getStats(): array
    {
        $byType = $this->countByMimeGroup();

        return [
            'ged' => [
                'documents' => array_sum($byType),
                'byType' => $byType,
                'categories' => $this->categoryRepository->count([]),
                'tags' => $this->tagRepository->count([]),
                'folders' => $this->folderRepository->count([]),
            ],
        ];
    }

    /**
     * Raw mime types folded into the four buckets the library already filters
     * by, so the chart says "Images" rather than listing `image/png` beside
     * `image/webp`.
     *
     * The folding asks {@see MimeGroupEnum::matches()} rather than reading the
     * strings here: that enum is one definition of what an image is, and its
     * docblock is explicit that anything holding a mime type asks it instead of
     * writing its own `str_starts_with`.
     *
     * A document with no mime type falls in no bucket, not even `other` - which
     * is what `matches()` answers for null, and why the total is summed from the
     * buckets rather than counted separately. A row the chart cannot place is
     * not a row the total should claim.
     *
     * @return array<string, int>
     */
    private function countByMimeGroup(): array
    {
        $counts = [];
        foreach (MimeGroupEnum::cases() as $group) {
            $counts[$group->value] = 0;
        }

        foreach ($this->documentRepository->countGroupedByMimeType() as $mimeType => $count) {
            foreach (MimeGroupEnum::cases() as $group) {
                if ($group->matches((string) $mimeType)) {
                    $counts[$group->value] += (int) $count;
                    break;
                }
            }
        }

        return $counts;
    }
}
