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
            // L'espace personnel n'a pas de nom : l'écran écrit « Mon espace »
            // dans la langue de qui lit.
            'name' => $space->getName(),
            'color' => $space->getColor(),
            'personal' => $space->isPersonal(),
            'access' => $space->getAccess()->value,
            'defaultRole' => $space->getDefaultRole()->value,
            'position' => $space->getPosition(),
            'published' => $space->isPublished(),
            'slug' => $space->getSlug(),
            'indexable' => $space->isIndexable(),
            // L'adresse complète, à copier telle quelle.
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
