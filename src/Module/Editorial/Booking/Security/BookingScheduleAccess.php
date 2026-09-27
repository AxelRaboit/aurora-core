<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Security;

use Aurora\Core\Scheduling\Access\ScheduledSourceAccessInterface;
use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Visitors' bookings, on the calendar, for the people who run the pages.
 *
 * A booking carries a name, an email and often a phone number, and it lands
 * in a calendar every calendar user sees. It is answered by whoever manages
 * the pages the booking was made from, so that is who sees it: the right to
 * edit publications.
 */
final readonly class BookingScheduleAccess implements ScheduledSourceAccessInterface
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function supports(string $sourceType): bool
    {
        return BookingSlotFinder::SOURCE === $sourceType;
    }

    public function visibleAmong(string $sourceType, array $sourceIds): array
    {
        return $this->authorizationChecker->isGranted('editorial.posts.edit') ? $sourceIds : [];
    }
}
