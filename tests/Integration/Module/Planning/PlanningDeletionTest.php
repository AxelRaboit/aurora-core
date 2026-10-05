<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendee;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Manager\PlanningManagerInterface;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function preg_match;

/**
 * Deleting a calendar leaves the rows on it to the database.
 *
 * A Doctrine cascade loaded every event, its attendees and alerts, and every
 * reminder, to delete them one statement each - and a synchronised calendar
 * holds every scheduled post. The foreign keys cascade already; the ORM now
 * lets them, and still everything on the calendar goes with it.
 */
final class PlanningDeletionTest extends IntegrationTestCase
{
    public function testACalendarGoesWithEverythingOnItWithoutLoadingAnyOfIt(): void
    {
        static::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $user);

        $planning = new Planning();
        $planning->setName('À supprimer')->setOwner($user);
        $entityManager->persist($planning);

        foreach (['Un', 'Deux', 'Trois'] as $title) {
            $event = new PlanningEvent();
            $event->setPlanning($planning)->setTitle($title)->setSpan(new DateTimeImmutable('2026-10-01 10:00'), new DateTimeImmutable('2026-10-01 11:00'));
            $alert = new PlanningEventAlert();
            $alert->setMinutesBefore(15);
            $event->addAlert($alert);
            $attendee = new PlanningEventAttendee();
            $attendee->setEvent($event)->setUser($user);
            $reminder = new PlanningReminder();
            $reminder->setPlanning($planning)->setTitle('Rappel '.$title)->setDueAt(new DateTimeImmutable('2026-10-01 09:00'));
            $entityManager->persist($event);
            $entityManager->persist($attendee);
            $entityManager->persist($reminder);
        }
        $entityManager->flush();
        $id = (int) $planning->getId();

        $entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        static::getContainer()->get(PlanningManagerInterface::class)->delete($entityManager->find(Planning::class, $id));

        $loaded = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => 1 === preg_match('/^SELECT (?!COUNT).*FROM (core_planning_events|core_planning_reminders|core_planning_event_attendees|core_planning_event_alerts) /s', (string) $query['sql']),
        );
        self::assertSame([], array_values($loaded), 'nothing on the calendar is loaded to be deleted');

        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM core_planning_events WHERE planning_id = ?', [$id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM core_planning_reminders WHERE planning_id = ?', [$id]));
    }
}
