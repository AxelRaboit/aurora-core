<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Serializer;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface NoteSpaceSerializerInterface
{
    /**
     * Un espace tel que le voit une personne : son rôle dit ce que l'écran
     * lui propose.
     *
     * @return array<string, mixed>
     */
    public function serialize(NoteSpaceInterface $space, CoreUserInterface $viewer, ?NoteSpaceRoleEnum $role): array;

    /** @return array<string, mixed> */
    public function serializeMember(NoteSpaceMemberInterface $member): array;
}
