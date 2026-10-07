<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceAccessLinkLabel;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use DateTimeImmutable;
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
            // The review deadline, in the same two forms and for the same
            // reasons as the publication date just above.
            'reviewBy' => $item->getReviewBy()?->format(DATE_ATOM),
            'reviewByLocal' => $item->getReviewBy()
                ?->setTimezone(new DateTimeZone($item->getSpace()->getTimezone()))
                ->format('Y-m-d\TH:i'),
            // Computed here rather than in the browser: "late" depends on the
            // server's time, and a badly set workstation clock would make the
            // delay appear or disappear without anything having moved.
            'lateForReview' => $item->isLateForReview(new DateTimeImmutable()),
            'showOnCalendar' => $item->isShownOnCalendar(),
            'approval' => $item->getApproval()->value,
            'approvalAt' => $item->getApprovalAt()?->format(DATE_ATOM),
            // Who answered, by the name their link carries. It used to be the
            // address, which travels all the way into the page of the other
            // guests of the same space: there is no account behind a link, but
            // there is a name, and it has been mandatory since it is read
            // here.
            'approvalBy' => $item->getApprovalByLink() instanceof SpaceAccessLinkInterface
                ? SpaceAccessLinkLabel::of($item->getApprovalByLink())
                : null,
            'createdAt' => $item->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
