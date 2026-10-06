<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Service;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Manager\NoteSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'espace de notes d'un espace client, ouvert la première fois qu'on en a
 * besoin.
 *
 * **À la demande plutôt qu'avec l'espace client**, pour la raison que donne
 * déjà le dossier de la médiathèque : un prospect qu'on ouvre et qu'on
 * referme le lendemain laisserait derrière lui un espace de notes vide dans le
 * panneau de chaque membre de son équipe. On l'ouvre quand quelqu'un écrit sa
 * première note, ou va voir l'onglet pour le faire.
 *
 * Un espace de notes que quelqu'un aurait sorti de là (retiré à la main par
 * la base, ou relâché) ne revient pas : le suivant est ouvert à neuf, comme le
 * dossier de la médiathèque après une mise à la corbeille.
 */
final readonly class SpaceNoteSpaceProvider
{
    /** Ce que porte `managedBy` sur l'espace de notes d'un espace client. */
    public const string MANAGED_BY = 'studio.customer_space';

    public function __construct(
        private NoteSpaceManagerInterface $noteSpaces,
        private SpaceNoteSpaceSync $sync,
        private EntityManagerInterface $entityManager,
    ) {}

    /** L'espace de notes de cet espace client, s'il en a un en service. */
    public function existing(CustomerSpaceInterface $space): ?NoteSpaceInterface
    {
        $noteSpace = $space->getNoteSpace();

        return $noteSpace instanceof NoteSpaceInterface && $noteSpace->isManaged() && !$noteSpace->getDeletedAt() instanceof DateTimeImmutable
            ? $noteSpace
            : null;
    }

    public function resolve(CustomerSpaceInterface $space): NoteSpaceInterface
    {
        $existing = $this->existing($space);

        if ($existing instanceof NoteSpaceInterface) {
            return $existing;
        }

        $noteSpace = $this->noteSpaces->createManaged($space->getName(), self::MANAGED_BY);
        $space->setNoteSpace($noteSpace);
        $this->entityManager->flush();

        $this->sync->sync($space);

        return $noteSpace;
    }
}
