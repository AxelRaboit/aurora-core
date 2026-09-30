<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;

/**
 * Ce qu'une personne a le droit de lire.
 *
 * Depuis les espaces, la réponse est celle de l'espace : on lit une note si
 * l'on peut lire l'espace où elle vit, et {@see NoteSpaceAccess} est le seul
 * à en décider. Ce service garde son nom et ses méthodes pour ceux qui
 * l'appelaient - la vue de lecture, les images, la liste « partagé avec moi ».
 *
 * Le partage en lecture d'avant, une date posée sur un dossier ou une note et
 * remontée de parent en parent, a disparu : la migration en a fait des
 * espaces ouverts à tout le back-office.
 */
final readonly class NoteReadScope
{
    public function __construct(
        private NoteSpaceAccess $access,
        private NoteSpaceRepository $spaces,
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
    ) {}

    /** La note demandée si la personne peut la lire, null sinon. */
    public function readableNote(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        return $this->access->readableNote($user, $id);
    }

    public function canRead(CoreUserInterface $user, MarkdownNoteInterface $note): bool
    {
        return $this->access->canReadNote($user, $note);
    }

    /**
     * Ce que les autres ouvrent à la personne : le contenu des espaces
     * qu'elle lit sans en être propriétaire.
     *
     * @return array{folders: list<NoteFolderInterface>, notes: list<MarkdownNoteInterface>}
     */
    public function sharedWith(CoreUserInterface $user): array
    {
        $ids = array_map(
            static fn (NoteSpaceInterface $space): int => (int) $space->getId(),
            array_values(array_filter(
                $this->spaces->findReadableFor($user),
                fn (NoteSpaceInterface $space): bool => !$space->isPersonal() && !$this->access->isOwner($user, $space),
            )),
        );

        if ([] === $ids) {
            return ['folders' => [], 'notes' => []];
        }

        $folders = array_values(array_filter(
            $this->folders->findAllForUser($user),
            static fn (NoteFolderInterface $folder): bool => in_array((int) $folder->getSpace()->getId(), $ids, true),
        ));

        $notes = array_values(array_filter(
            $this->notes->findAllWithContentForUser($user),
            static fn (MarkdownNoteInterface $note): bool => in_array((int) $note->getSpace()->getId(), $ids, true),
        ));

        return ['folders' => $folders, 'notes' => $notes];
    }
}
