<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\View;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Configuration\Storage\Setting\StorageSettings;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Search\DocumentSearchFilters;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializerInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\DocumentFolder\Serializer\DocumentFolderSerializerInterface;
use Aurora\Module\Ged\DocumentTag\Repository\DocumentTagRepository;
use Aurora\Module\Ged\DocumentTag\Serializer\DocumentTagSerializerInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function array_sum;
use function is_int;

final readonly class DocumentsViewBuilder
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentSerializerInterface $documentSerializer,
        private DocumentCategoryRepository $categoryRepository,
        private DocumentCategorySerializerInterface $categorySerializer,
        private DocumentTagRepository $tagRepository,
        private DocumentTagSerializerInterface $tagSerializer,
        private DocumentFolderRepository $folderRepository,
        private DocumentFolderSerializerInterface $folderSerializer,
        private UrlGeneratorInterface $urlGenerator,
        private StorageSettings $storageSettings,
        private DocumentUsageService $usageService,
        private ThemeContext $themeContext,
    ) {}

    /**
     * What a document can be filed under: categories, tags and the folder
     * tree. The document's own page edits these too, with the same lists as
     * the library.
     *
     * @return array{categories: list<array<string, mixed>>, tags: list<array<string, mixed>>, folders: list<array<string, mixed>>}
     */
    public function classificationOptions(): array
    {
        return [
            'categories' => array_map($this->categorySerializer->serialize(...), $this->categoryRepository->findAllOrdered()),
            'tags' => array_map($this->tagSerializer->serialize(...), $this->tagRepository->findAllOrdered()),
            'folders' => $this->serializeFoldersWithCounts(),
        ];
    }

    public function indexView(PaginationRequest $pagination, bool $originalsOnly = true, string $sort = 'date', string $direction = 'desc'): array
    {
        $categories = array_map(
            $this->categorySerializer->serialize(...),
            $this->categoryRepository->findAllOrdered(),
        );

        $tags = array_map(
            $this->tagSerializer->serialize(...),
            $this->tagRepository->findAllOrdered(),
        );

        $folders = $this->serializeFoldersWithCounts();

        return [
            // The folders already counted above, rather than counted again.
            // Painted with the same families and order the screen will ask for
            // on its first reload, or the page would jump once loaded.
            'documents' => $this->buildListPayload($pagination, originalsOnly: $originalsOnly, folders: $folders, sort: $sort, direction: $direction),
            'categories' => $categories,
            'tags' => $tags,
            'folders' => $folders,
            'search' => $pagination->search ?? '',
            'createPath' => $this->urlGenerator->generate('suite_ged_documents_create'),
            'showPath' => $this->urlGenerator->generate('suite_ged_documents_show', ['id' => '__id__']),
            'versionsPath' => $this->urlGenerator->generate('suite_ged_documents_versions', ['id' => '__id__']),
            'usagePath' => $this->urlGenerator->generate('suite_ged_documents_usage', ['id' => '__id__']),
            'alternatesPath' => $this->urlGenerator->generate('suite_ged_documents_alternates', ['id' => '__id__']),
            'alternateLabels' => $this->documentRepository->findAlternateLabels(),
            'updatePath' => $this->urlGenerator->generate('suite_ged_documents_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('suite_ged_documents_delete', ['id' => '__id__']),
            'cropPath' => $this->urlGenerator->generate('suite_ged_documents_crop', ['id' => '__id__']),
            'recolorPath' => $this->urlGenerator->generate('suite_ged_documents_recolor', ['id' => '__id__']),
            // Offered first when declining a visual in another colour.
            'themeColor' => $this->themeContext->primaryColor(),
            'bulkDeletePath' => $this->urlGenerator->generate('suite_ged_documents_bulk_delete'),
            'restorePath' => $this->urlGenerator->generate('suite_ged_documents_restore', ['id' => '__id__']),
            'forceDeletePath' => $this->urlGenerator->generate('suite_ged_documents_force_delete', ['id' => '__id__']),
            'bulkRestorePath' => $this->urlGenerator->generate('suite_ged_documents_bulk_restore'),
            'emptyTrashPath' => $this->urlGenerator->generate('suite_ged_documents_empty_trash'),
            'listPath' => $this->urlGenerator->generate('suite_ged_documents_list'),
            // Media-style move endpoints (single + bulk) - power the sidebar
            // drag&drop and the bulk-move modal. The dedicated /suite/ged/folders
            // page remains untouched and continues to handle folder-tree management.
            'movePath' => $this->urlGenerator->generate('suite_ged_documents_move', ['id' => '__id__']),
            'storagePath' => $this->urlGenerator->generate('suite_ged_documents_storage', ['id' => '__id__']),
            'bulkStoragePath' => $this->urlGenerator->generate('suite_ged_documents_bulk_storage'),
            // "Everything onto that suite", without a selection. Its own
            // endpoint rather than the bulk one with every id in the payload:
            // the list of ids belongs on the server, where it cannot go stale
            // between the page being drawn and the button being pressed.
            'relocateAllPath' => $this->urlGenerator->generate('suite_ged_documents_relocate_all'),
            // Whether the screen may offer to move a document at all. There is
            // nowhere to move it to until an administrator has configured a
            // second backend, and an action that can only fail is worse than
            // no action.
            'storageRelocationAvailable' => $this->storageSettings->isRelocationAvailable(),
            'bulkMovePath' => $this->urlGenerator->generate('suite_ged_documents_bulk_move'),
            'bulkCategoryPath' => $this->urlGenerator->generate('suite_ged_documents_bulk_category'),
            // Sidebar folder CRUD reuses the existing /suite/ged/folders endpoints,
            // so create/edit/delete behave identically across both pages.
            'folderCreatePath' => $this->urlGenerator->generate('suite_ged_folders_create'),
            'folderEditPath' => $this->urlGenerator->generate('suite_ged_folders_update', ['id' => '__id__']),
            'folderDeletePath' => $this->urlGenerator->generate('suite_ged_folders_delete', ['id' => '__id__']),
            'folderMovePath' => $this->urlGenerator->generate('suite_ged_folders_move', ['id' => '__id__']),
            // GED-owned upload endpoint - no coupling to the Media library.
            // The form POSTs the file here, gets back the metadata, then
            // submits the regular JSON create/update with that metadata.
            'uploadPath' => $this->urlGenerator->generate('suite_ged_documents_upload'),
        ];
    }

    /** @param list<array<string, mixed>>|null $folders the sidebar's folders, when the caller has counted them */
    public function buildListPayload(
        PaginationRequest $pagination,
        ?int $categoryId = null,
        ?int $tagId = null,
        ?int $folderId = null,
        ?DocumentStatusEnum $status = null,
        ?MimeGroupEnum $mimeGroup = null,
        bool $rootOnly = false,
        ?StorageDiskEnum $storageDisk = null,
        bool $trashed = false,
        bool $originalsOnly = false,
        ?array $folders = null,
        string $sort = 'date',
        string $direction = 'desc',
        DocumentSearchFilters $filters = new DocumentSearchFilters(),
    ): array {
        $result = $this->documentRepository->findPaginated(
            $pagination->page,
            search: $pagination->search,
            categoryId: $categoryId,
            tagId: $tagId,
            folderId: $folderId,
            status: $status,
            mimeGroup: $mimeGroup,
            rootOnly: $rootOnly,
            storageDisk: $storageDisk,
            trashed: $trashed,
            originalsOnly: $originalsOnly,
            sort: $sort,
            direction: $direction,
            filters: $filters,
        );

        return [
            'success' => true,
            'items' => $this->withUsageCounts(
                array_map($this->documentSerializer->serialize(...), $result['items']),
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            // Sidebar refreshes counts on every navigation so the badges next
            // to folder names stay in sync after moves / deletes / uploads.
            'folders' => $folders ?? $this->serializeFoldersWithCounts(),
        ];
    }

    /**
     * Each row told whether anything is drawing it.
     *
     * The count travels with the listing rather than behind a second call, so
     * the badge is there when the row is painted and no screen ever shows a
     * document whose state is still loading.
     *
     * **One question for the whole page.** Asked row by row, a page of fifty
     * would cost fifty lookups in each of the five modules, and the three that
     * walk their source - posts, deliverables, space notes - would walk it fifty
     * times over. {@see DocumentUsageService::countUsagesFor()} takes the page
     * at once, and the providers that can answer in bulk do it in one query or
     * one pass.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function withUsageCounts(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (is_int($item['id'] ?? null)) {
                $ids[] = $item['id'];
            }
        }

        if ([] === $ids) {
            return $items;
        }

        // Same page, one more question: which rows are originals, and with
        // which alternates. The card shows one chip per member before the row
        // is opened, and a chip can say whether that member is used.
        $family = $this->documentRepository->findAlternatesForOriginals($ids);
        $alternateIds = [];
        foreach ($family as $members) {
            foreach ($members as $member) {
                $alternateIds[] = (int) $member->getId();
            }
        }

        // By kind of source, so the card can say where each member is used
        // - "two pages, one deliverable" - and not only whether.
        $byType = $this->usageService->countUsagesByTypeFor([...$ids, ...$alternateIds]);
        $counts = array_map(array_sum(...), $byType);
        // Trashed alternates count here: the family rule refuses to make an
        // original of them an alternate, so the screen must not offer it.
        $withTrashed = $this->documentRepository->countAlternatesFor($ids, includeTrashed: true);

        return array_map(
            function (array $item) use ($counts, $byType, $family, $withTrashed): array {
                $id = $item['id'] ?? null;
                // Whole documents, not summaries: a picker that chooses the
                // yellow copy from its original's card hands it to the page
                // editor, which needs its address and size like any pick.
                $members = array_map(
                    fn ($member): array => [
                        ...$this->documentSerializer->serialize($member),
                        'label' => $member->getAlternateLabel(),
                        'usageCount' => $counts[$member->getId()] ?? 0,
                        'usageByType' => $byType[$member->getId()] ?? [],
                    ],
                    $family[$id] ?? [],
                );

                return [
                    ...$item,
                    'usageCount' => $counts[$id] ?? 0,
                    'usageByType' => $byType[$id] ?? [],
                    'alternateCount' => count($members),
                    'alternates' => $members,
                    'familyLocked' => ($withTrashed[$id] ?? 0) > 0,
                ];
            },
            $items,
        );
    }

    /**
     * The folder tree on its own, for a caller that has no document listing to
     * carry it - the side menu's module panel, which mounts on every GED page
     * and is handed no props by the menu.
     *
     * Same shape and same counts as the `folders` key of the two payloads
     * above, so the panel and the documents page never disagree about how many
     * documents a folder holds.
     *
     * @return array<string, mixed>
     */
    public function folderTreePayload(): array
    {
        return [
            'success' => true,
            'folders' => $this->serializeFoldersWithCounts(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeFoldersWithCounts(): array
    {
        $serializer = $this->folderSerializer->withDocumentCounts(
            $this->documentRepository->countGroupedByFolders(),
        );

        return array_map(
            $serializer->serialize(...),
            $this->folderRepository->findAllOrdered(),
        );
    }
}
