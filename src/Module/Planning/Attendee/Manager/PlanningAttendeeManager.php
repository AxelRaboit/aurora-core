<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Attendee\Manager;

use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * One person's answer to one invitation.
 *
 * A manager rather than controller code: finding the row, mutating it and flushing
 * is exactly the orchestration `convention_thin_controller` keeps out of a
 * controller.
 */
#[AsAlias(PlanningAttendeeManagerInterface::class)]
class PlanningAttendeeManager implements PlanningAttendeeManagerInterface
{
    public function __construct(protected readonly EntityManagerInterface $entityManager) {}

    /**
     * Records an answer, and says whether there was an invitation to answer.
     *
     * False rather than an exception for "not invited", because that is an ordinary
     * request from somebody looking at somebody else's meeting - the caller turns
     * it into a refusal, and nothing here has to know what a 422 is.
     */
    public function respond(
        PlanningEventInterface $event,
        CoreUserInterface $user,
        PlanningAttendeeStatusEnum $status,
    ): bool {
        foreach ($event->getAttendees() as $attendee) {
            if ($attendee->getUser()->getId() !== $user->getId()) {
                continue;
            }

            $attendee->respond($status, new DateTimeImmutable());
            $this->entityManager->flush();

            return true;
        }

        return false;
    }
}
