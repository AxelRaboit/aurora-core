<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\View;

use Aurora\Core\Support\Num;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Craft\Service\CraftClient;
use Aurora\Module\Notes\Favorite\Manager\NoteFavoriteManagerInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Folder\Serializer\NoteFolderSerializerInterface;
use Aurora\Module\Notes\Folder\Service\NoteFolderHierarchy;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Setting\MarkdownNoteSettingEnum;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteMemberRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Serializer\NoteSpaceSerializerInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
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
        private NoteSpaceAccess $spaceAccess,
        private NoteSpaceRepository $spaceRepository,
        private NoteFavoriteManagerInterface $favorites,
        private NoteSpaceSerializerInterface $spaceSerializer,
        private MarkdownNoteMemberRepository $memberRepository,
        private CraftClient $craftClient,
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
        $serializer = $this->folderSerializer->withFavorites($this->favorites->mapFor($user)['folders'])->withCounts(
            $this->folderRepository->countNotesPerFolderForUser($user),
            $this->folderRepository->countChildrenPerFolderForUser($user),
        );

        $folders = array_map(
            $serializer->serialize(...),
            $this->folderRepository->findAllForUser($user),
        );

        return [
            'activeId' => $activeId,
            'personalSpaceId' => $this->spaceAccess->personalSpace($user)->getId(),
            'spaces' => $this->spacesFor($user),
            'canCreateSpace' => $this->spaceAccess->canCreateShared(),
            'folderId' => $folder?->getId(),
            'notes' => $this->withExcerpts($this->noteRepository->findFlatListForUser($user), $user),
            'folders' => $folders,
            // The notes handed to this person on their own, with their role.
            // Their space is closed to the reader, so nothing on screen could
            // say whether they may write them - see the repository.
            'sharedNotes' => $this->memberRepository->findRolesFor($user),
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
            ...$this->spacePaths(),
            // The Craft import only exists on screen if the installation has
            // opened the connection: an action that leads to an empty list and
            // an explanation is an action that disappoints every time.
            'craftEnabled' => $this->craftClient->isConfigured(),
            'craftPaths' => [
                'documents' => $this->urlGenerator->generate('suite_notes_craft_documents'),
                'import' => $this->urlGenerator->generate('suite_notes_craft_import'),
                'refresh' => $this->urlGenerator->generate('suite_notes_craft_refresh', ['id' => '__id__']),
            ],
            'imageMaxEdge' => (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::ImageMaxEdge),
            'imageQuality' => $this->imageQualityRatio(),
        ];
    }

    /**
     * What is needed to draw a single note, without the back office.
     *
     * The title index is that of the whole notebook, and not of a scope as
     * for a share: the reader is at home here, so a wiki link always leads
     * somewhere.
     *
     * @return array<string, mixed>
     */
    public function readView(CoreUserInterface $user, MarkdownNoteInterface $note): array
    {
        // Loaded once and passed to whoever needs them: the title index, the
        // reading order and the tree each read the same list, and each read
        // decrypts every visible title.
        $rows = $this->noteRepository->findFlatListForUser($user);
        $folders = $this->folderRepository->findAllForUser($user);

        // Links and next pages stay within the note's space: a `[[Budget]]`
        // written in a space leads to that space's "Budget", not to the one
        // of another notebook that would carry the same title.
        $spaceId = (int) $note->getSpace()->getId();
        $spaceRows = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['spaceId'] === $spaceId));
        $spaceFolders = array_values(array_filter($folders, static fn (NoteFolderInterface $folder): bool => (int) $folder->getSpace()->getId() === $spaceId));

        $titles = $this->ownTitleIndex($spaceRows);
        $neighbours = $this->readingNeighbours($spaceFolders, $spaceRows, (int) $note->getId());

        return [
            'note' => $note,
            // The note's path from the root of its space.
            'breadcrumb' => array_map(
                static fn (NoteFolderInterface $one): array => ['id' => $one->getId(), 'name' => $one->getName(), 'color' => $one->getColor()],
                $this->hierarchy->pathTo($note->getFolder()),
            ),
            'canEdit' => $this->spaceAccess->canWriteNote($user, $note),
            // Handed to them on its own: the page says so rather than letting
            // somebody wonder why a note of a notebook they do not know is
            // sitting in their reader.
            'sharedWithViewer' => $this->spaceAccess->noteRoleIn($user, $note)?->value,
            // Reading is enough to pin: favorites belong to the person.
            'favorited' => null !== $this->favorites->favoritedAt($user, $note),
            'favoritePath' => $this->urlGenerator->generate('suite_notes_markdown_favorite', ['id' => $note->getId()]),
            'previous' => $neighbours['previous'],
            'next' => $neighbours['next'],
            'libraryPath' => $this->urlGenerator->generate('suite_notes_markdown'),
            'folderShowPath' => $this->urlGenerator->generate('suite_notes_markdown_folder', ['id' => '__id__']),
            'readNotePath' => $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => '__id__']),
            'searchPath' => $this->urlGenerator->generate('suite_notes_markdown_search'),
            // Images go through a route that applies the note's read rule
            // and its author's key: the address written in the text is the
            // author's, which we rewrite.
            'imagePrefix' => str_replace('__filename__', '', $this->urlGenerator->generate('suite_notes_markdown_images_serve', ['filename' => '__filename__'])),
            'noteImagePath' => $this->urlGenerator->generate('suite_notes_markdown_images_read', ['noteId' => $note->getId(), 'filename' => '__filename__']),
            'backPath' => $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $note->getId()]),
            'exportPath' => $this->urlGenerator->generate('suite_notes_markdown_export_one', ['id' => $note->getId()]),
            'cover' => [
                'url' => $note->getCoverUrl(),
                'creditName' => $note->getCoverCreditName(),
                'creditUrl' => $note->getCoverCreditUrl(),
                'position' => $note->getCoverPosition(),
            ],
            'appearance' => $note->getAppearance()->value,
            'titleIndex' => $titles,
            ...$this->readerTree($user, $folders, $rows),
        ];
    }

    /**
     * What the reader shows on the left: the whole notebook, and what was
     * shared with them, apart.
     *
     * Reading mode is a space of its own, without the back office around it:
     * it therefore carries its own tree rather than the menu panel. Titles
     * only - no body is decrypted to draw a tree.
     *
     * @param list<NoteFolderInterface>  $folders
     * @param list<array<string, mixed>> $rows
     *
     * @return array{treeFolders: list<array<string, mixed>>, treeNotes: list<array<string, mixed>>, treeSpaces: list<array<string, mixed>>}
     */
    private function readerTree(CoreUserInterface $user, array $folders, array $rows): array
    {
        return [
            ...$this->treeRows($folders, $rows),
            'treeSpaces' => $this->spacesFor($user),
            // Like the library's: the tree groups the notes handed over one
            // by one apart, and the roles are the only thing that marks them.
            'sharedNotes' => $this->memberRepository->findRolesFor($user),
        ];
    }

    /**
     * The folders and notes as the reader's tree wants them: titles only,
     * never bodies.
     *
     * @param list<NoteFolderInterface>  $folders
     * @param list<array<string, mixed>> $rows
     *
     * @return array{treeFolders: list<array<string, mixed>>, treeNotes: list<array<string, mixed>>}
     */
    private function treeRows(array $folders, array $rows): array
    {
        $folders = array_map(static fn (NoteFolderInterface $one): array => [
            'id' => $one->getId(),
            'parentId' => $one->getParent()?->getId(),
            'name' => $one->getName(),
            'color' => $one->getColor(),
            'position' => $one->getPosition(),
            'spaceId' => $one->getSpace()->getId(),
        ], $folders);

        $notes = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'folderId' => null === ($row['folderId'] ?? null) ? null : (int) $row['folderId'],
            'position' => $row['position'],
            'spaceId' => $row['spaceId'],
        ], $rows);

        return [
            'treeFolders' => $folders,
            'treeNotes' => $notes,
        ];
    }

    /**
     * The spaces a person reads, their own first, with their role in each
     * one - computed in one go, not space by space.
     *
     * @return list<array<string, mixed>>
     */
    public function spacesFor(CoreUserInterface $user): array
    {
        $this->spaceAccess->personalSpace($user);
        $spaces = $this->spaceRepository->findReadableFor($user);
        $roles = $this->spaceAccess->rolesFor($user, $spaces);

        return array_map(
            fn (NoteSpaceInterface $space): array => $this->spaceSerializer->serialize($space, $user, $roles[(int) $space->getId()] ?? null),
            $spaces,
        );
    }

    /**
     * The titles of the whole notebook: at home, a wiki link always leads
     * somewhere.
     *
     * The flat list rather than the entities: it carries the titles and the
     * ids, and nothing else. Loading nine hundred encrypted bodies to build a
     * title index would cost one decryption per note, for nothing.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function ownTitleIndex(array $rows): array
    {
        $titles = [];

        foreach ($rows as $one) {
            $title = mb_strtolower(mb_trim((string) ($one['title'] ?? '')));

            if ('' !== $title) {
                $titles[$title] = (int) $one['id'];
            }
        }

        return $titles;
    }

    /**
     * What is needed to read a note of a published space, without an account.
     *
     * **Everything comes from the space, nothing from the person**: there is
     * none. The tree, the title index and the neighbouring pages are those of
     * the space alone; a wiki link to a note elsewhere therefore leads
     * nowhere, which is exactly what we want from a page open to everyone.
     * The addresses are those of the public reading, never those of the back
     * office.
     *
     * @return array<string, mixed>
     */
    public function publicView(NoteSpaceInterface $space, MarkdownNoteInterface $note): array
    {
        $rows = $this->noteRepository->findFlatListInSpace($space);
        $folders = $this->folderRepository->findLivingInSpace($space);
        $neighbours = $this->readingNeighbours($folders, $rows, (int) $note->getId());
        $slug = (string) $space->getSlug();

        return [
            'note' => $note,
            'space' => $space,
            'publicTitle' => (string) $space->getName(),
            'indexable' => $space->isIndexable(),
            'breadcrumb' => array_map(
                static fn (NoteFolderInterface $one): array => ['id' => $one->getId(), 'name' => $one->getName(), 'color' => $one->getColor()],
                $this->hierarchy->pathTo($note->getFolder()),
            ),
            'canEdit' => false,
            'favorited' => false,
            'favoritePath' => '',
            'previous' => $neighbours['previous'],
            'next' => $neighbours['next'],
            'libraryPath' => $this->urlGenerator->generate('notes_public_space', ['slug' => $slug]),
            'folderShowPath' => '',
            'readNotePath' => $this->urlGenerator->generate('notes_public_note', ['slug' => $slug, 'id' => '__id__']),
            'searchPath' => '',
            // The text cites its images by the back office address; the page
            // rewrites them to the public route, limited to this note.
            'imagePrefix' => str_replace('__filename__', '', $this->urlGenerator->generate('suite_notes_markdown_images_serve', ['filename' => '__filename__'])),
            'noteImagePath' => $this->urlGenerator->generate('notes_public_image', ['slug' => $slug, 'id' => $note->getId(), 'filename' => '__filename__']),
            'backPath' => '',
            'cover' => [
                'url' => $note->getCoverUrl(),
                'creditName' => $note->getCoverCreditName(),
                'creditUrl' => $note->getCoverCreditUrl(),
                'position' => $note->getCoverPosition(),
            ],
            'appearance' => $note->getAppearance()->value,
            'titleIndex' => $this->ownTitleIndex($rows),
            ...$this->treeRows($folders, $rows),
            'treeSpaces' => [],
            'sharedNotes' => [],
        ];
    }

    /** The first note of a space in reading order: where its public page opens. */
    public function firstInSpace(NoteSpaceInterface $space): ?int
    {
        return $this->readingOrder(
            $this->folderRepository->findLivingInSpace($space),
            $this->noteRepository->findFlatListInSpace($space),
        )['order'][0] ?? null;
    }

    /**
     * The first note of the notebook, in reading order: where the reader
     * opens when you enter it without a note, by its address or its shortcut.
     */
    public function firstInReadingOrder(CoreUserInterface $user): ?int
    {
        // The first space that has notes, the personal one first.
        $rows = $this->noteRepository->findFlatListForUser($user);
        $folders = $this->folderRepository->findAllForUser($user);

        foreach ($this->spaceRepository->findReadableFor($user) as $space) {
            $id = (int) $space->getId();
            $first = $this->readingOrder(
                array_values(array_filter($folders, static fn (NoteFolderInterface $folder): bool => (int) $folder->getSpace()->getId() === $id)),
                array_values(array_filter($rows, static fn (array $row): bool => (int) $row['spaceId'] === $id)),
            )['order'][0] ?? null;

            if (null !== $first) {
                return $first;
            }
        }

        return null;
    }

    /**
     * All of the person's notes, in tree order.
     *
     * @param list<NoteFolderInterface>  $folders
     * @param list<array<string, mixed>> $rows
     *
     * @return array{order: list<int>, titles: array<int, string>}
     */
    private function readingOrder(array $folders, array $rows): array
    {
        // Folders and notes share one order among siblings, as in the tree:
        // a note can come before a folder. Ties go to the folder, then to
        // the older id, which is what the tree does too.
        $childrenOf = [];
        foreach ($folders as $folder) {
            $childrenOf[(int) ($folder->getParent()?->getId() ?? 0)][] = [(int) $folder->getPosition(), 0, (int) $folder->getId()];
        }

        $titles = [];
        foreach ($rows as $row) {
            $childrenOf[(int) ($row['folderId'] ?? 0)][] = [(int) ($row['position'] ?? 0), 1, (int) $row['id']];
            $titles[(int) $row['id']] = (string) ($row['title'] ?? '');
        }

        foreach ($childrenOf as &$children) {
            usort($children, static fn (array $left, array $right): int => $left <=> $right);
        }

        unset($children);

        $order = [];
        $seen = [];
        $walk = static function (int $folderId) use (&$walk, &$order, &$seen, $childrenOf): void {
            // A damaged notebook where a folder would contain itself must not
            // make the page loop.
            if (isset($seen[$folderId])) {
                return;
            }

            $seen[$folderId] = true;

            foreach ($childrenOf[$folderId] ?? [] as [, $kind, $id]) {
                if (0 === $kind) {
                    $walk($id);
                } else {
                    $order[] = $id;
                }
            }
        };
        $walk(0);

        return ['order' => $order, 'titles' => $titles];
    }

    /**
     * The previous note and the next one, in tree order.
     *
     * It is what makes reading mode a reading of the notebook and not of a
     * note: you move from one note to the next as you turn a page, in the
     * order the panel files them: folders and notes mixed, each level
     * according to its position.
     *
     * @param list<NoteFolderInterface>  $folders
     * @param list<array<string, mixed>> $rows
     *
     * @return array{previous: ?array{id: int, title: string}, next: ?array{id: int, title: string}}
     */
    private function readingNeighbours(array $folders, array $rows, int $noteId): array
    {
        ['order' => $order, 'titles' => $titles] = $this->readingOrder($folders, $rows);

        $at = array_search($noteId, $order, true);
        if (false === $at) {
            return ['previous' => null, 'next' => null];
        }

        $pick = static fn (?int $id): ?array => null === $id ? null : ['id' => $id, 'title' => $titles[$id] ?? ''];

        return [
            'previous' => $pick($order[$at - 1] ?? null),
            'next' => $pick($order[$at + 1] ?? null),
        ];
    }

    /**
     * The excerpts, stuck onto the list rows.
     *
     * One more query, and not a join: the body is encrypted, so the excerpt
     * is computed in PHP after decryption, and doing it here rather than in
     * the list query keeps that one light for the screens that do not want
     * it.
     *
     * @param list<array<string, mixed>> $notes
     *
     * @return list<array<string, mixed>>
     */
    private function withExcerpts(array $notes, CoreUserInterface $user): array
    {
        $excerpts = $this->noteRepository->findExcerptsForUser($user);

        return array_map(
            static fn (array $note): array => [...$note, 'excerpt' => $excerpts[(int) $note['id']] ?? null],
            $notes,
        );
    }

    /** @return array<string, string> */
    private function notePaths(): array
    {
        return [
            'listPath' => $this->urlGenerator->generate('suite_notes_markdown_list'),
            'libraryPath' => $this->urlGenerator->generate('suite_notes_markdown'),
            'showPath' => $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('suite_notes_markdown_create'),
            'updatePath' => $this->urlGenerator->generate('suite_notes_markdown_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('suite_notes_markdown_delete', ['id' => '__id__']),
            'movePath' => $this->urlGenerator->generate('suite_notes_markdown_move', ['id' => '__id__']),
            'favoritePath' => $this->urlGenerator->generate('suite_notes_markdown_favorite', ['id' => '__id__']),
            'reorderPath' => $this->urlGenerator->generate('suite_notes_markdown_reorder'),
            'duplicatePath' => $this->urlGenerator->generate('suite_notes_markdown_duplicate', ['id' => '__id__']),
            'templatePath' => $this->urlGenerator->generate('suite_notes_markdown_template', ['id' => '__id__']),
            'fromTemplatePath' => $this->urlGenerator->generate('suite_notes_markdown_from_template', ['id' => '__id__']),
            'dailyPath' => $this->urlGenerator->generate('suite_notes_markdown_daily'),
            'revisionsPath' => $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => '__id__']),
            'revisionPath' => $this->urlGenerator->generate('suite_notes_markdown_revision', ['id' => '__id__', 'revisionId' => '__revisionId__']),
            'revisionRestorePath' => $this->urlGenerator->generate('suite_notes_markdown_revision_restore', ['id' => '__id__', 'revisionId' => '__revisionId__']),
            'backlinksPath' => $this->urlGenerator->generate('suite_notes_markdown_backlinks', ['id' => '__id__']),
            'unlinkedMentionsPath' => $this->urlGenerator->generate('suite_notes_markdown_unlinked_mentions', ['id' => '__id__']),
            'graphPath' => $this->urlGenerator->generate('suite_notes_markdown_graph'),
            'exportPath' => $this->urlGenerator->generate('suite_notes_markdown_export'),
            'exportOnePath' => $this->urlGenerator->generate('suite_notes_markdown_export_one', ['id' => '__id__']),
            'importPath' => $this->urlGenerator->generate('suite_notes_markdown_import'),
            'searchPath' => $this->urlGenerator->generate('suite_notes_markdown_search'),
            'tagsListPath' => $this->urlGenerator->generate('suite_notes_markdown_tags_list'),
            'tagsRenamePath' => $this->urlGenerator->generate('suite_notes_markdown_tags_rename'),
            'tagsMergePath' => $this->urlGenerator->generate('suite_notes_markdown_tags_merge'),
            'tagsDeletePath' => $this->urlGenerator->generate('suite_notes_markdown_tags_delete'),
            'sharesListPath' => $this->urlGenerator->generate('suite_notes_markdown_shares_list', ['noteId' => '__id__']),
            'sharesPreviewPath' => $this->urlGenerator->generate('suite_notes_markdown_shares_preview', ['noteId' => '__id__']),
            'sharesCreatePath' => $this->urlGenerator->generate('suite_notes_markdown_shares_create'),
            'sharesRevokePath' => $this->urlGenerator->generate('suite_notes_markdown_shares_revoke', ['id' => '__id__']),
            'liveBeatPath' => $this->urlGenerator->generate('suite_notes_markdown_live_beat', ['id' => '__id__']),
            'peopleListPath' => $this->urlGenerator->generate('suite_notes_markdown_people_list', ['noteId' => '__id__']),
            'peopleSetPath' => $this->urlGenerator->generate('suite_notes_markdown_people_set', ['noteId' => '__id__']),
            'peopleRemovePath' => $this->urlGenerator->generate('suite_notes_markdown_people_remove', ['noteId' => '__id__', 'userId' => '__user__']),
            'imageUploadPath' => $this->urlGenerator->generate('suite_notes_markdown_images_upload'),
            'readPath' => $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => '__id__']),
            'coversSearchPath' => $this->urlGenerator->generate('suite_notes_markdown_covers_search'),
        ];
    }

    /**
     * The routes of the spaces, in one object, like those of the folders.
     *
     * @return array<string, array<string, string>>
     */
    private function spacePaths(): array
    {
        return [
            'spacePaths' => [
                'list' => $this->urlGenerator->generate('suite_notes_spaces_list'),
                'create' => $this->urlGenerator->generate('suite_notes_spaces_create'),
                'show' => $this->urlGenerator->generate('suite_notes_spaces_show', ['id' => '__id__']),
                'update' => $this->urlGenerator->generate('suite_notes_spaces_update', ['id' => '__id__']),
                'delete' => $this->urlGenerator->generate('suite_notes_spaces_delete', ['id' => '__id__']),
                'membersSet' => $this->urlGenerator->generate('suite_notes_spaces_members_set', ['id' => '__id__']),
                'membersRemove' => $this->urlGenerator->generate('suite_notes_spaces_members_remove', ['id' => '__id__', 'userId' => '__user__']),
                'people' => $this->urlGenerator->generate('suite_notes_spaces_people'),
                'publish' => $this->urlGenerator->generate('suite_notes_spaces_publish', ['id' => '__id__']),
            ],
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
                'list' => $this->urlGenerator->generate('suite_notes_markdown_folders_list'),
                'create' => $this->urlGenerator->generate('suite_notes_markdown_folders_create'),
                'update' => $this->urlGenerator->generate('suite_notes_markdown_folders_update', ['id' => '__id__']),
                'move' => $this->urlGenerator->generate('suite_notes_markdown_folders_move', ['id' => '__id__']),
                'delete' => $this->urlGenerator->generate('suite_notes_markdown_folders_delete', ['id' => '__id__']),
                'reorder' => $this->urlGenerator->generate('suite_notes_markdown_folders_reorder'),
                'favorite' => $this->urlGenerator->generate('suite_notes_markdown_folders_favorite', ['id' => '__id__']),
                'show' => $this->urlGenerator->generate('suite_notes_markdown_folder', ['id' => '__id__']),
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
