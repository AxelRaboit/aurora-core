<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceNote\Dto\SpaceNoteInputInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prendre et reprendre une note dans un espace.
 *
 * **Rien n'est supprimé de la médiathèque en supprimant une note.** Une image
 * posée dans un corps est un document comme un autre : d'autres notes peuvent
 * la porter, une publication aussi, et la médiathèque a déjà un écran qui dit
 * qui l'utilise. L'écran propose de la jeter quand plus personne ne s'en sert,
 * ce que le registre d'usages sait répondre - la même règle que les pièces
 * jointes d'une fiche, et pour la même raison : supprimer ce qui a l'air
 * inutilisé est une décision qui appartient à quelqu'un.
 */
#[AsAlias(SpaceNoteManagerInterface::class)]
class SpaceNoteManager implements SpaceNoteManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly Security $security,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function create(CustomerSpaceInterface $space, SpaceNoteInputInterface $input): SpaceNoteInterface
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            throw new FieldException('title', $this->translator->trans('backend.studio.space_notes.errors.needs_account'));
        }

        $note = $this->createNote();
        $note->setSpace($space)->takenBy($user, $this->labelOf($user));

        $this->applyInput($note, $input);

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'space_note.created', 'SpaceNote', $note->getId(), $this->auditPayload($note));

        return $note;
    }

    public function update(SpaceNoteInterface $note, SpaceNoteInputInterface $input): void
    {
        $this->applyInput($note, $input);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'space_note.updated', 'SpaceNote', $note->getId(), $this->auditPayload($note));
    }

    public function markImportedFromCraft(SpaceNoteInterface $note, string $craftDocumentId): void
    {
        $note->setCraftDocumentId($craftDocumentId);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'studio',
            'space_note.imported_from_craft',
            'SpaceNote',
            $note->getId(),
            ['craftDocumentId' => $craftDocumentId],
        );
    }

    public function togglePinned(SpaceNoteInterface $note): void
    {
        $note->setPinned(!$note->isPinned());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'space_note.pinned', 'SpaceNote', $note->getId(), $this->auditPayload($note));
    }

    public function delete(SpaceNoteInterface $note): void
    {
        $this->auditLogger->log('studio', 'space_note.deleted', 'SpaceNote', $note->getId(), $this->auditPayload($note));

        $this->entityManager->remove($note);
        $this->entityManager->flush();
    }

    protected function applyInput(SpaceNoteInterface $note, SpaceNoteInputInterface $input): void
    {
        $note
            ->setTitle($input->getTitle())
            ->setBody($input->getBody())
            ->setColourSlot($input->getColourSlot())
            ->setPinned($input->isPinned())
            ->setVisibility($input->getVisibility());
    }

    /**
     * Instancie l'entité concrète. À surcharger dans une sous-classe pour
     * rendre une classe substituée côté client - `resolve_target_entities` ne
     * touche que les relations Doctrine, pas les `new` directs.
     */
    protected function createNote(): SpaceNoteInterface
    {
        return new SpaceNote();
    }

    protected function labelOf(CoreUserInterface $user): string
    {
        return $user instanceof User ? $user->getName() : $user->getUserIdentifier();
    }

    /**
     * Ce que porte chaque entrée du journal.
     *
     * **Le corps n'y est pas.** Une note est déjà stockée une fois ; la
     * recopier dans le journal met ce que quelqu'un a écrit dans un second
     * endroit que personne ne pense à purger.
     *
     * L'action, elle, est écrite en toutes lettres à chaque appel plutôt que
     * passée ici : le contrôle de dérive des libellés lit le code à la
     * recherche de `log('module', 'action')`, et une action passée en variable
     * est un angle mort qu'il refuse d'avoir.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(SpaceNoteInterface $note): array
    {
        return [
            'spaceId' => $note->getSpace()->getId(),
            'spaceName' => $note->getSpace()->getName(),
            'title' => $note->getTitle(),
            // Passer une note personnelle en partagee est le geste qu'on
            // voudra pouvoir retrouver, et il ne se voit pas dans le titre.
            'visibility' => $note->getVisibility()->value,
        ];
    }
}
