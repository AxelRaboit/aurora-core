<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Attendee\Repository;

use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningEventAttendee>
 */
class PlanningEventAttendeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningEventAttendee::class);
    }
}
