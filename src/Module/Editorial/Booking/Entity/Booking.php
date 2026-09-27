<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Entity;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * One slot a visitor took through an appointment-booking zone.
 *
 * What gives a booking an identity of its own. The calendar keys the dates
 * a module announces on `(sourceType, sourceId)`, and a booking used to
 * borrow the page's id: the second booking on the same page was the same
 * source as the first, and the unique index refused it.
 *
 * Holds when and where, not who. The visitor's name and how to reach them
 * travel once, to the calendar entry the owner answers from, rather than
 * being kept twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_editorial_bookings')]
#[ORM\Index(name: 'idx_booking_zone', columns: ['post_id', 'zone_id'])]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_editorial_booking_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PostInterface::class)]
        #[ORM\JoinColumn(name: 'post_id', nullable: false, onDelete: 'CASCADE')]
        private PostInterface $post,
        #[ORM\Column(length: 36)]
        private string $zoneId,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $startAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $endAt,
    ) {
        // Stored in UTC, as the calendar stores its own: the column keeps no
        // offset, and a Paris 10:00 would be read back as 10:00 UTC.
        $this->startAt = $startAt->setTimezone(new DateTimeZone('UTC'));
        $this->endAt = $endAt->setTimezone(new DateTimeZone('UTC'));
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): PostInterface
    {
        return $this->post;
    }

    public function getZoneId(): string
    {
        return $this->zoneId;
    }

    public function getStartAt(): DateTimeImmutable
    {
        return $this->startAt;
    }

    public function getEndAt(): DateTimeImmutable
    {
        return $this->endAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
