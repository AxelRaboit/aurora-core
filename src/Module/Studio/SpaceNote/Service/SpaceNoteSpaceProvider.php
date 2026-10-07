<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Service;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Manager\NoteSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A client space's notes space, opened the first time it is needed.
 *
 * **On demand rather than with the client space**, for the reason the media
 * library folder already gives: a prospect opened and closed the next day
 * would leave behind an empty notes space in the panel of every member of its
 * team. It is opened when someone writes its first note, or goes to the tab to
 * do so.
 *
 * A notes space someone took out of there (removed by hand in the database,
 * or released) does not come back: the next one is opened fresh, like the
 * media library folder after being moved to the trash.
 */
final readonly class SpaceNoteSpaceProvider
{
    /** What `managedBy` holds on a client space's notes space. */
    public const string MANAGED_BY = 'studio.customer_space';

    public function __construct(
        private NoteSpaceManagerInterface $noteSpaces,
        private SpaceNoteSpaceSync $sync,
        private EntityManagerInterface $entityManager,
    ) {}

    /** This client space's notes space, if it has one in service. */
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
