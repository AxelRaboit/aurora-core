<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\View;

use Aurora\Core\Support\Num;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Folder\Serializer\NoteFolderSerializerInterface;
use Aurora\Module\Notes\Folder\Service\NoteFolderHierarchy;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Setting\MarkdownNoteSettingEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class MarkdownNotesViewBuilder
{
    public function __construct(
        private MarkdownNoteRepository $noteRepository,
        private NoteFolderRepository $folderRepository,
        private NoteFolderSerializerInterface $folderSerializer,
        private NoteFolderHierarchy $hierarchy,
        private UrlGeneratorInterface $urlGenerator,
        private SettingRepository $settingRepository,
    ) {}

    /**
     * What the page needs to draw itself, before any request of its own.
     *
     * @param ?int                 $activeId the note the address names, null on a listing
     * @param ?NoteFolderInterface $folder   the folder being browsed, null at the root
     *
     * @return array<string, mixed>
     */
    public function indexView(CoreUserInterface $user, ?int $activeId = null, ?NoteFolderInterface $folder = null): array
    {
        $serializer = $this->folderSerializer->withCounts(
            $this->folderRepository->countNotesPerFolderForUser($user),
            $this->folderRepository->countChildrenPerFolderForUser($user),
        );

        $folders = array_map(
            static fn (NoteFolderInterface $one): array => $serializer->serialize($one),
            $this->folderRepository->findAllForUser($user),
        );

        return [
            'activeId' => $activeId,
            'folderId' => $folder?->getId(),
            'notes' => $this->noteRepository->findFlatListForUser($user),
            'folders' => $folders,
            // The chain the breadcrumb draws, resolved server-side: the page
            // knows where it is before its first fetch, so a reload does not
            // flash the root.
            'breadcrumb' => array_map(
                static fn (NoteFolderInterface $one): array => ['id' => $one->getId(), 'name' => $one->getName()],
                $this->hierarchy->pathTo($folder),
            ),
            'maxDepth' => NoteFolderHierarchy::MAX_DEPTH,
            ...$this->notePaths(),
            ...$this->folderPaths(),
            'imageMaxEdge' => (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::ImageMaxEdge),
            'imageQuality' => $this->imageQualityRatio(),
        ];
    }

    /** @return array<string, string> */
    private function notePaths(): array
    {
        return [
            'listPath' => $this->urlGenerator->generate('backend_notes_markdown_list'),
            'libraryPath' => $this->urlGenerator->generate('backend_notes_markdown'),
            'browsePath' => $this->urlGenerator->generate('backend_notes_markdown_browse'),
            'showPath' => $this->urlGenerator->generate('backend_notes_markdown_show', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('backend_notes_markdown_create'),
            'updatePath' => $this->urlGenerator->generate('backend_notes_markdown_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('backend_notes_markdown_delete', ['id' => '__id__']),
            'movePath' => $this->urlGenerator->generate('backend_notes_markdown_move', ['id' => '__id__']),
            'reorderPath' => $this->urlGenerator->generate('backend_notes_markdown_reorder'),
            'backlinksPath' => $this->urlGenerator->generate('backend_notes_markdown_backlinks', ['id' => '__id__']),
            'unlinkedMentionsPath' => $this->urlGenerator->generate('backend_notes_markdown_unlinked_mentions', ['id' => '__id__']),
            'graphPath' => $this->urlGenerator->generate('backend_notes_markdown_graph'),
            'exportPath' => $this->urlGenerator->generate('backend_notes_markdown_export'),
            'exportOnePath' => $this->urlGenerator->generate('backend_notes_markdown_export_one', ['id' => '__id__']),
            'importPath' => $this->urlGenerator->generate('backend_notes_markdown_import'),
            'searchPath' => $this->urlGenerator->generate('backend_notes_markdown_search'),
            'tagsListPath' => $this->urlGenerator->generate('backend_notes_markdown_tags_list'),
            'tagsRenamePath' => $this->urlGenerator->generate('backend_notes_markdown_tags_rename'),
            'tagsMergePath' => $this->urlGenerator->generate('backend_notes_markdown_tags_merge'),
            'tagsDeletePath' => $this->urlGenerator->generate('backend_notes_markdown_tags_delete'),
            'sharesListPath' => $this->urlGenerator->generate('backend_notes_markdown_shares_list', ['noteId' => '__id__']),
            'sharesPreviewPath' => $this->urlGenerator->generate('backend_notes_markdown_shares_preview', ['noteId' => '__id__']),
            'sharesCreatePath' => $this->urlGenerator->generate('backend_notes_markdown_shares_create'),
            'sharesRevokePath' => $this->urlGenerator->generate('backend_notes_markdown_shares_revoke', ['id' => '__id__']),
            'imageUploadPath' => $this->urlGenerator->generate('backend_notes_markdown_images_upload'),
        ];
    }

    /**
     * The folder routes, in one object.
     *
     * Grouped rather than flattened into the component's props: the page
     * already takes two dozen path strings, and a second family of them
     * arriving one prop at a time is how that list got there.
     *
     * @return array<string, array<string, string>>
     */
    private function folderPaths(): array
    {
        return [
            'folderPaths' => [
                'list' => $this->urlGenerator->generate('backend_notes_markdown_folders_list'),
                'create' => $this->urlGenerator->generate('backend_notes_markdown_folders_create'),
                'update' => $this->urlGenerator->generate('backend_notes_markdown_folders_update', ['id' => '__id__']),
                'move' => $this->urlGenerator->generate('backend_notes_markdown_folders_move', ['id' => '__id__']),
                'delete' => $this->urlGenerator->generate('backend_notes_markdown_folders_delete', ['id' => '__id__']),
                'reorder' => $this->urlGenerator->generate('backend_notes_markdown_folders_reorder'),
                'show' => $this->urlGenerator->generate('backend_notes_markdown_folder', ['id' => '__id__']),
            ],
        ];
    }

    /**
     * Read the WebP-quality setting (stored as an int percentage so the
     * Settings UI's `int` renderer can edit it) and project it back into
     * the [0..1] float the canvas encoder expects. Delegates to
     * {@see Num::percentToRatio()} for the clamping.
     */
    private function imageQualityRatio(): float
    {
        return Num::percentToRatio(
            (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::ImageQualityPct),
        );
    }
}
