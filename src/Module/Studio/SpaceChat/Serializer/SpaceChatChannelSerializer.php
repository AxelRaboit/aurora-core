<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Serializer;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SpaceChatChannelSerializerInterface::class)]
class SpaceChatChannelSerializer implements SpaceChatChannelSerializerInterface
{
    /**
     * One room, as both sides read it.
     *
     * The member labels rather than their accounts: the panel prints who is in
     * the room, and it must keep printing them after somebody's account is
     * deleted - the row keeps the name it was added under.
     *
     * `openToClient` travels to the client's page too, where it is always true
     * by construction. It is sent anyway, because the same component draws both
     * sides and a key that exists on one side only is the kind of difference
     * that gets discovered by a blank badge.
     *
     * @return array<string, mixed>
     */
    public function serialize(SpaceChatChannelInterface $channel): array
    {
        $members = [];

        foreach ($channel->getMembers() as $member) {
            $members[] = [
                'id' => $member->getId(),
                'label' => $member->getLabel(),
                'fromClient' => $member->isFromClient(),
            ];
        }

        return [
            'id' => $channel->getId(),
            'name' => $channel->getName(),
            'kind' => $channel->getKind()->value,
            'isMain' => SpaceChatChannelKindEnum::Main === $channel->getKind(),
            'isDirect' => SpaceChatChannelKindEnum::Direct === $channel->getKind(),
            'openToClient' => $channel->isOpenToClient(),
            'members' => $members,
        ];
    }

    public function serializeFor(
        SpaceChatChannelInterface $channel,
        ?CoreUserInterface $viewerUser,
        ?SpaceAccessLinkInterface $viewerLink,
    ): array {
        $view = $this->serialize($channel);

        if (SpaceChatChannelKindEnum::Direct !== $channel->getKind()) {
            return $view;
        }

        // Le nom de l'autre, jamais le sien. Une conversation privée s'appelle
        // « Marie Dupont » pour le client et du nom du client pour Marie : une
        // liste où chacun se voit soi-même n'aide personne à retrouver un fil.
        foreach ($channel->getMembers() as $member) {
            $isViewer = ($viewerUser instanceof CoreUserInterface && $member->getUser()?->getId() === $viewerUser->getId())
                || ($viewerLink instanceof SpaceAccessLinkInterface && $member->getLink()?->getId() === $viewerLink->getId());

            if (!$isViewer) {
                $view['name'] = $member->getLabel();

                break;
            }
        }

        return $view;
    }
}
