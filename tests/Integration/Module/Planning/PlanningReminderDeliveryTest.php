<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventReminder;
use Aurora\Module\Planning\Event\Repository\PlanningEventReminderRepository;
use Aurora\Module\Planning\Event\Service\PlanningReminderNotifier;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * When a reminder goes out, and when it does not go out again.
 *
 * The worker runs every minute for the life of the application, so the two things
 * worth proving are the two failures the reader would actually notice: a
 * reminder that never arrives, and one that arrives twice.
 */
final class PlanningReminderDeliveryTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private PlanningReminderNotifier $notifier;

    private PlanningEventReminderRepository $reminders;

    private User $admin;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        // A client is booted even though nothing here makes a request: it is what
        // gives the container its request context, and the notifier generates an
        // absolute URL for the notification's link.
        static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = static::getContainer()->get(PlanningReminderNotifier::class);
        $this->reminders = static::getContainer()->get(PlanningEventReminderRepository::class);
        static::getContainer()->get(UrlGeneratorInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testAReminderThatIsDueIsSentAndStampedOnce(): void
    {
        $reminder = $this->reminder('2026-08-23 14:00', 30);
        $now = new DateTimeImmutable('2026-08-23 13:30');

        self::assertSame(1, $this->notifier->sendDue($now));
        self::assertNotNull($reminder->getSentAt());

        // The second run is the one that matters. Nothing changed between them
        // except the stamp, so a reminder going out twice here is the bug the
        // reader reports as "it told me twice".
        self::assertSame(0, $this->notifier->sendDue($now->modify('+1 minute')));
    }

    public function testAReminderNotYetDueIsLeftAlone(): void
    {
        $reminder = $this->reminder('2026-08-23 14:00', 30);

        self::assertSame(0, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 13:29')));
        self::assertNull($reminder->getSentAt());
    }

    /**
     * A worker stopped for an hour comes back to an hour of reminders.
     *
     * `findDue` has no lower bound on lateness for this: a reminder arriving late
     * is information, and one that never arrives is a bug. The alternative -
     * skipping anything older than a few minutes - would silently drop every
     * reminder of a deploy window.
     */
    public function testALateWorkerStillSends(): void
    {
        $reminder = $this->reminder('2026-08-23 14:00', 30);

        self::assertSame(1, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 15:45')));
        self::assertNotNull($reminder->getSentAt());
    }

    /**
     * The query the worker runs every minute, asked directly.
     *
     * Both conditions in one test because either alone would pass with the index
     * half wrong: due-and-unsent is one predicate to the reader and two to
     * Postgres.
     */
    public function testFindDueWantsBothDueAndUnsent(): void
    {
        $due = $this->reminder('2026-08-23 14:00', 30);
        $later = $this->reminder('2026-08-23 18:00', 30);
        $alreadySent = $this->reminder('2026-08-23 12:00', 30);
        $alreadySent->markSent(new DateTimeImmutable('2026-08-23 11:30'));
        $this->entityManager->flush();

        $found = [];
        foreach ($this->reminders->findDue(new DateTimeImmutable('2026-08-23 13:30')) as $reminder) {
            $found[] = $reminder->getId();
        }

        self::assertContains($due->getId(), $found);
        self::assertNotContains($later->getId(), $found);
        self::assertNotContains($alreadySent->getId(), $found);
    }

    private function reminder(string $start, int $minutesBefore): PlanningEventReminder
    {
        $planning = new Planning();
        $planning->setName('Rappels '.$start);
        $planning->setOwner($this->admin);
        $this->entityManager->persist($planning);

        $startAt = new DateTimeImmutable($start);
        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle('Réunion '.$start);
        $event->setSpan($startAt, $startAt->modify('+1 hour'));
        $this->entityManager->persist($event);

        $reminder = new PlanningEventReminder();
        $reminder->setMinutesBefore($minutesBefore);
        $event->addReminder($reminder);
        $this->entityManager->persist($reminder);

        $this->entityManager->flush();

        $this->created[] = [Planning::class, (int) $planning->getId()];
        $this->created[] = [PlanningEvent::class, (int) $event->getId()];

        return $reminder;
    }
}
