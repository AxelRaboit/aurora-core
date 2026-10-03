<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\View;

use Aurora\Core\Support\Num;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Favorite\Manager\NoteFavoriteManagerInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Folder\Serializer\NoteFolderSerializerInterface;
use Aurora\Module\Notes\Folder\Service\NoteFolderHierarchy;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Setting\MarkdownNoteSettingEnum;
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
        private NoteSpaceRepository $spaces,
        private NoteFavoriteManagerInterface $favorites,
        private NoteSpaceSerializerInterface $spaceSerializer,
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
            'imageMaxEdge' => (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::ImageMaxEdge),
            'imageQuality' => $this->imageQualityRatio(),
        ];
    }

    /**
     * Ce qu'il faut pour dessiner une note seule, sans le back-office.
     *
     * L'index des titres est celui de tout le carnet, et non d'un périmètre
     * comme pour un partage : le lecteur est ici chez lui, donc un wiki-lien
     * mène toujours quelque part.
     *
     * @return array<string, mixed>
     */
    public function readView(CoreUserInterface $user, MarkdownNoteInterface $note): array
    {
        // Chargés une fois et passés à qui en a besoin : l'index des titres,
        // l'ordre de lecture et l'arborescence lisaient chacun la même liste,
        // et chaque lecture déchiffre tous les titres visibles.
        $rows = $this->noteRepository->findFlatListForUser($user);
        $folders = $this->folderRepository->findAllForUser($user);

        // Les liens et les pages suivantes restent dans l'espace de la note :
        // un `[[Budget]]` écrit dans un espace mène au « Budget » de cet
        // espace, pas à celui d'un autre carnet qui porterait le même titre.
        $spaceId = (int) $note->getSpace()->getId();
        $spaceRows = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['spaceId'] === $spaceId));
        $spaceFolders = array_values(array_filter($folders, static fn (NoteFolderInterface $folder): bool => (int) $folder->getSpace()->getId() === $spaceId));

        $titles = $this->ownTitleIndex($spaceRows);
        $neighbours = $this->readingNeighbours($spaceFolders, $spaceRows, (int) $note->getId());

        return [
            'note' => $note,
            // Le chemin de la note depuis la racine de son espace.
            'breadcrumb' => array_map(
                static fn (NoteFolderInterface $one): array => ['id' => $one->getId(), 'name' => $one->getName(), 'color' => $one->getColor()],
                $this->hierarchy->pathTo($note->getFolder()),
            ),
            'canEdit' => $this->spaceAccess->canWriteNote($user, $note),
            // Lire suffit pour épingler : les favoris sont à la personne.
            'favorited' => null !== $this->favorites->favoritedAt($user, $note),
            'favoritePath' => $this->urlGenerator->generate('backend_notes_markdown_favorite', ['id' => $note->getId()]),
            'previous' => $neighbours['previous'],
            'next' => $neighbours['next'],
            'libraryPath' => $this->urlGenerator->generate('backend_notes_markdown'),
            'folderShowPath' => $this->urlGenerator->generate('backend_notes_markdown_folder', ['id' => '__id__']),
            'readNotePath' => $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => '__id__']),
            'searchPath' => $this->urlGenerator->generate('backend_notes_markdown_search'),
            // Les images passent par une route qui applique la règle de
            // lecture de la note et la clé de son auteur : l'adresse écrite
            // dans le texte est celle de l'auteur, que l'on réécrit.
            'imagePrefix' => str_replace('__filename__', '', $this->urlGenerator->generate('backend_notes_markdown_images_serve', ['filename' => '__filename__'])),
            'noteImagePath' => $this->urlGenerator->generate('backend_notes_markdown_images_read', ['noteId' => $note->getId(), 'filename' => '__filename__']),
            'backPath' => $this->urlGenerator->generate('backend_notes_markdown_show', ['id' => $note->getId()]),
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
     * Ce que le lecteur montre à gauche : tout son carnet, et ce qu'on lui a
     * partagé, à part.
     *
     * Le mode lecture est un espace à lui, sans le back-office autour : il
     * porte donc sa propre arborescence plutôt que le panneau du menu. Les
     * titres seulement - aucun corps n'est déchiffré pour dessiner un arbre.
     *
     * @param list<NoteFolderInterface>  $folders
     * @param list<array<string, mixed>> $rows
     *
     * @return array{treeFolders: list<array<string, mixed>>, treeNotes: list<array<string, mixed>>, treeSpaces: list<array<string, mixed>>}
     */
    private function readerTree(CoreUserInterface $user, array $folders, array $rows): array
    {
        return [...$this->treeRows($folders, $rows), 'treeSpaces' => $this->spacesFor($user)];
    }

    /**
     * Les dossiers et les notes tels que l'arbre du lecteur les veut : les
     * titres seulement, jamais les corps.
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
     * Les espaces qu'une personne lit, le sien d'abord, avec son rôle dans
     * chacun - calculé en une fois, pas espace par espace.
     *
     * @return list<array<string, mixed>>
     */
    public function spacesFor(CoreUserInterface $user): array
    {
        $this->spaceAccess->personalSpace($user);
        $spaces = $this->spaces->findReadableFor($user);
        $roles = $this->spaceAccess->rolesFor($user, $spaces);

        return array_map(
            fn (NoteSpaceInterface $space): array => $this->spaceSerializer->serialize($space, $user, $roles[(int) $space->getId()] ?? null),
            $spaces,
        );
    }

    /**
     * Les titres de tout son carnet : chez soi, un wiki-lien mène toujours
     * quelque part.
     *
     * La liste à plat plutôt que les entités : elle porte les titres et les
     * identifiants, et rien d'autre. Charger neuf cents corps chiffrés pour
     * construire un index de titres serait le prix d'un déchiffrement par
     * note, pour rien.
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
     * Ce qu'il faut pour lire une note d'un espace publié, sans compte.
     *
     * **Tout vient de l'espace, rien de la personne** : il n'y en a pas.
     * L'arbre, l'index des titres et les pages voisines sont ceux de l'espace
     * seul ; un wiki-lien vers une note d'ailleurs ne mène donc nulle part,
     * ce qui est exactement ce qu'on veut d'une page ouverte à tous. Les
     * adresses sont celles de la lecture publique, jamais celles du
     * back-office.
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
            // Le texte cite ses images par l'adresse du back-office ; la page
            // les réécrit vers la route publique, bornée à cette note.
            'imagePrefix' => str_replace('__filename__', '', $this->urlGenerator->generate('backend_notes_markdown_images_serve', ['filename' => '__filename__'])),
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
        ];
    }

    /** La première note d'un espace dans l'ordre de lecture : là où s'ouvre sa page publique. */
    public function firstInSpace(NoteSpaceInterface $space): ?int
    {
        return $this->readingOrder(
            $this->folderRepository->findLivingInSpace($space),
            $this->noteRepository->findFlatListInSpace($space),
        )['order'][0] ?? null;
    }

    /**
     * La première note du carnet, dans l'ordre de lecture : là où s'ouvre le
     * lecteur quand on y entre sans note, par son adresse ou son raccourci.
     */
    public function firstInReadingOrder(CoreUserInterface $user): ?int
    {
        // Le premier espace qui a des notes, le personnel d'abord.
        $rows = $this->noteRepository->findFlatListForUser($user);
        $folders = $this->folderRepository->findAllForUser($user);

        foreach ($this->spaces->findReadableFor($user) as $space) {
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
     * Toutes les notes de la personne, dans l'ordre de l'arborescence.
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
            usort($children, static fn (array $a, array $b): int => $a <=> $b);
        }
        unset($children);

        $order = [];
        $seen = [];
        $walk = static function (int $folderId) use (&$walk, &$order, &$seen, $childrenOf): void {
            // Un carnet abîmé dont un dossier se contiendrait lui-même ne
            // doit pas faire tourner la page.
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
     * La note d'avant et celle d'après, dans l'ordre de l'arborescence.
     *
     * C'est ce qui fait du mode lecture une lecture du carnet et pas d'une
     * note : on avance d'une note à la suivante comme on tourne une page, dans
     * l'ordre où le panneau les range : dossiers et notes mêlés, chaque niveau
     * selon sa position.
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
     * Les extraits, collés sur les lignes de la liste.
     *
     * Une requête de plus, et pas une jointure : le corps est chiffré, donc
     * l'extrait se calcule en PHP après déchiffrement, et le faire ici
     * plutôt que dans la requête de liste garde celle-ci légère pour les
     * écrans qui n'en veulent pas.
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
            'listPath' => $this->urlGenerator->generate('backend_notes_markdown_list'),
            'libraryPath' => $this->urlGenerator->generate('backend_notes_markdown'),
            'showPath' => $this->urlGenerator->generate('backend_notes_markdown_show', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('backend_notes_markdown_create'),
            'updatePath' => $this->urlGenerator->generate('backend_notes_markdown_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('backend_notes_markdown_delete', ['id' => '__id__']),
            'movePath' => $this->urlGenerator->generate('backend_notes_markdown_move', ['id' => '__id__']),
            'favoritePath' => $this->urlGenerator->generate('backend_notes_markdown_favorite', ['id' => '__id__']),
            'reorderPath' => $this->urlGenerator->generate('backend_notes_markdown_reorder'),
            'duplicatePath' => $this->urlGenerator->generate('backend_notes_markdown_duplicate', ['id' => '__id__']),
            'templatePath' => $this->urlGenerator->generate('backend_notes_markdown_template', ['id' => '__id__']),
            'fromTemplatePath' => $this->urlGenerator->generate('backend_notes_markdown_from_template', ['id' => '__id__']),
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
            'readPath' => $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => '__id__']),
            'coversSearchPath' => $this->urlGenerator->generate('backend_notes_markdown_covers_search'),
        ];
    }

    /**
     * Les routes des espaces, en un objet, comme celles des dossiers.
     *
     * @return array<string, array<string, string>>
     */
    private function spacePaths(): array
    {
        return [
            'spacePaths' => [
                'list' => $this->urlGenerator->generate('backend_notes_spaces_list'),
                'create' => $this->urlGenerator->generate('backend_notes_spaces_create'),
                'show' => $this->urlGenerator->generate('backend_notes_spaces_show', ['id' => '__id__']),
                'update' => $this->urlGenerator->generate('backend_notes_spaces_update', ['id' => '__id__']),
                'delete' => $this->urlGenerator->generate('backend_notes_spaces_delete', ['id' => '__id__']),
                'membersSet' => $this->urlGenerator->generate('backend_notes_spaces_members_set', ['id' => '__id__']),
                'membersRemove' => $this->urlGenerator->generate('backend_notes_spaces_members_remove', ['id' => '__id__', 'userId' => '__user__']),
                'people' => $this->urlGenerator->generate('backend_notes_spaces_people'),
                'publish' => $this->urlGenerator->generate('backend_notes_spaces_publish', ['id' => '__id__']),
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
                'list' => $this->urlGenerator->generate('backend_notes_markdown_folders_list'),
                'create' => $this->urlGenerator->generate('backend_notes_markdown_folders_create'),
                'update' => $this->urlGenerator->generate('backend_notes_markdown_folders_update', ['id' => '__id__']),
                'move' => $this->urlGenerator->generate('backend_notes_markdown_folders_move', ['id' => '__id__']),
                'delete' => $this->urlGenerator->generate('backend_notes_markdown_folders_delete', ['id' => '__id__']),
                'reorder' => $this->urlGenerator->generate('backend_notes_markdown_folders_reorder'),
                'favorite' => $this->urlGenerator->generate('backend_notes_markdown_folders_favorite', ['id' => '__id__']),
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
