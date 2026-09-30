<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Manager;

use Aurora\Module\Notes\Space\Dto\NoteSpaceInputInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface NoteSpaceManagerInterface
{
    /** Un espace partagé, dont la personne qui le crée est propriétaire. */
    public function create(CoreUserInterface $owner, NoteSpaceInputInterface $input): NoteSpaceInterface;

    public function update(NoteSpaceInterface $space, NoteSpaceInputInterface $input): void;

    /**
     * Retire un espace partagé, avec tout ce qu'il range, sans rien
     * détruire : il disparaît pour tout le monde et peut revenir.
     */
    public function delete(NoteSpaceInterface $space): void;

    public function restore(NoteSpaceInterface $space): void;

    /** Inscrit une personne, ou change son rôle si elle l'est déjà. */
    public function setMember(NoteSpaceInterface $space, CoreUserInterface $user, NoteSpaceRoleEnum $role): NoteSpaceMemberInterface;

    /** @return bool faux quand la personne n'était pas inscrite */
    public function removeMember(NoteSpaceInterface $space, CoreUserInterface $user): bool;
}
