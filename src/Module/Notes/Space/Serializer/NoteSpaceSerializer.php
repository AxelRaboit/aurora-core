<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Serializer;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(NoteSpaceSerializerInterface::class)]
class NoteSpaceSerializer implements NoteSpaceSerializerInterface
{
    public function __construct(
        protected readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function serialize(NoteSpaceInterface $space, CoreUserInterface $viewer, ?NoteSpaceRoleEnum $role): array
    {
        return [
            'id' => $space->getId(),
            // The personal space has no name: the screen writes "Mon espace"
            // in the reader's language.
            'name' => $space->getName(),
            'color' => $space->getColor(),
            'personal' => $space->isPersonal(),
            // Configured elsewhere (the note space of a client space): its
            // name, access and members are not configured from here.
            'managed' => $space->isManaged(),
            'access' => $space->getAccess()->value,
            'defaultRole' => $space->getDefaultRole()->value,
            'position' => $space->getPosition(),
            'published' => $space->isPublished(),
            'slug' => $space->getSlug(),
            'indexable' => $space->isIndexable(),
            // The full address, to copy as it is.
            'publicUrl' => $space->isPublished() && null !== $space->getSlug()
                ? $this->urlGenerator->generate('notes_public_space', ['slug' => $space->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)
                : null,
            'role' => $role?->value,
            'canWrite' => true === $role?->canWrite(),
            'canManage' => true === $role?->canManage(),
            'isOwner' => $space->getOwner()?->getId() === $viewer->getId(),
            'ownerName' => $space->isPersonal() ? null : $space->getOwner()?->getName(),
        ];
    }

    public function serializeMember(NoteSpaceMemberInterface $member): array
    {
        return [
            'userId' => $member->getUser()->getId(),
            'name' => $member->getUser()->getName(),
            'role' => $member->getRole()->value,
        ];
    }
}
