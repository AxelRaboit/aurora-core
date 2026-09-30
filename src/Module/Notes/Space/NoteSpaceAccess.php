<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Service\NoteReadScope;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Qui peut écrire quoi, dans les deux espaces.
 *
 * **Un seul endroit décide.** Chaque route qui écrit chargeait sa note par
 * son propriétaire ; avec un carnet commun, ce n'est plus la question : une
 * note d'équipe appartient à qui l'a créée, et d'autres ont le droit de
 * l'écrire. Laisser chaque contrôleur répondre à sa façon, c'est ouvrir une
 * note par oubli dans l'un d'eux.
 *
 * La règle : dans son carnet, seul le propriétaire écrit ; dans celui de
 * l'équipe, ceux qui ont `notes.team.edit` - et les administrateurs, comme
 * pour tout droit d'Aurora. La lecture, elle, est dans {@see NoteReadScope}.
 */
final readonly class NoteSpaceAccess
{
    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
    ) {}

    public function canWriteTeam(): bool
    {
        return $this->authorization->isGranted(NoteSpaceEnum::TEAM_EDIT);
    }

    public function canWriteSpace(NoteSpaceEnum $space): bool
    {
        return NoteSpaceEnum::Personal === $space || $this->canWriteTeam();
    }

    public function canWriteNote(CoreUserInterface $user, MarkdownNoteInterface $note): bool
    {
        return $note->isTeam() ? $this->canWriteTeam() : $note->getUser()->getId() === $user->getId();
    }

    public function canWriteFolder(CoreUserInterface $user, NoteFolderInterface $folder): bool
    {
        return $folder->isTeam() ? $this->canWriteTeam() : $folder->getUser()->getId() === $user->getId();
    }

    /**
     * La note si la personne peut l'écrire, null sinon - corbeille comprise :
     * restaurer ou supprimer pour de bon est aussi une écriture.
     */
    public function writableNote(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        $note = $this->notes->find($id);

        return $note instanceof MarkdownNoteInterface && $this->canWriteNote($user, $note) ? $note : null;
    }

    public function writableFolder(CoreUserInterface $user, int $id): ?NoteFolderInterface
    {
        $folder = $this->folders->find($id);

        return $folder instanceof NoteFolderInterface && $this->canWriteFolder($user, $folder) ? $folder : null;
    }
}
