<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceAccessLinkLabel;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(SpaceContentItemSerializerInterface::class)]
class SpaceContentItemSerializer implements SpaceContentItemSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceContentItemInterface $item): array
    {
        $scheduledAt = $item->getScheduledAt();

        return [
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'body' => $item->getBody(),
            'columnId' => $item->getColumn()->getId(),
            'position' => $item->getPosition(),
            // Two shapes of the same moment, on purpose. The instant is what a
            // calendar places and compares; the wall clock is what the form
            // field holds, already in the space's zone, so the browser never
            // has to convert and can never convert it wrong.
            'scheduledAt' => $scheduledAt?->format(DATE_ATOM),
            'scheduledAtLocal' => $scheduledAt
                ?->setTimezone(new DateTimeZone($item->getSpace()->getTimezone()))
                ->format('Y-m-d\TH:i'),
            'showOnCalendar' => $item->isShownOnCalendar(),
            'approval' => $item->getApproval()->value,
            'approvalAt' => $item->getApprovalAt()?->format(DATE_ATOM),
            // Qui a répondu, par le nom que porte son lien. C'était l'adresse,
            // qui voyage jusque dans la page des autres invités du même
            // espace : il n'y a pas de compte derrière un lien, mais il y a un
            // nom, et il est obligatoire depuis qu'on le lit ici.
            'approvalBy' => $item->getApprovalByLink() instanceof SpaceAccessLinkInterface
                ? SpaceAccessLinkLabel::of($item->getApprovalByLink())
                : null,
            'createdAt' => $item->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
