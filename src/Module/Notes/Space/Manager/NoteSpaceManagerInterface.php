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

    /**
     * Un espace partagé que quelque chose d'autre règle (`$managedBy`) : sans
     * propriétaire, ouvert à ses seuls membres.
     */
    public function createManaged(string $name, string $managedBy): NoteSpaceInterface;

    /**
     * Remet le nom et les membres d'un espace réglé d'ailleurs sur ceux
     * donnés : qui n'y est plus est désinscrit, qui y arrive est inscrit.
     *
     * @param list<array{user: CoreUserInterface, role: NoteSpaceRoleEnum}> $members
     */
    public function syncManaged(NoteSpaceInterface $space, string $name, array $members): void;

    /**
     * Ce qui réglait l'espace a disparu : il passe à la corbeille et
     * redevient un espace ordinaire, que les administrateurs reprennent.
     */
    public function releaseManaged(NoteSpaceInterface $space): void;

    public function update(NoteSpaceInterface $space, NoteSpaceInputInterface $input): void;

    /**
     * Retire un espace partagé, avec tout ce qu'il range, sans rien
     * détruire : il disparaît pour tout le monde et peut revenir.
     */
    public function delete(NoteSpaceInterface $space): void;

    public function restore(NoteSpaceInterface $space): void;

    /**
     * Ouvre l'espace en lecture sur le web, à cette adresse. L'adresse est
     * déjà validée et libre : le contrôleur l'a vérifié.
     */
    public function publish(NoteSpaceInterface $space, string $slug, bool $indexable): void;

    /** Referme l'espace au web ; son adresse reste la sienne pour une prochaine fois. */
    public function unpublish(NoteSpaceInterface $space): void;

    /** Inscrit une personne, ou change son rôle si elle l'est déjà. */
    public function setMember(NoteSpaceInterface $space, CoreUserInterface $user, NoteSpaceRoleEnum $role): NoteSpaceMemberInterface;

    /** @return bool faux quand la personne n'était pas inscrite */
    public function removeMember(NoteSpaceInterface $space, CoreUserInterface $user): bool;
}
