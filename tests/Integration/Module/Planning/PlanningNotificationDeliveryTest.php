<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Repository\PlanningEventAlertRepository;
use Aurora\Module\Planning\Event\Service\PlanningNotifier;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * When a alert goes out, and when it does not go out again.
 *
 * The worker runs every minute for the life of the application, so the two things
 * worth proving are the two failures the reader would actually notice: a
 * alert that never arrives, and one that arrives twice.
 */
final class PlanningNotificationDeliveryTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private PlanningNotifier $notifier;

    private PlanningEventAlertRepository $alerts;

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
        $this->notifier = static::getContainer()->get(PlanningNotifier::class);
        $this->alerts = static::getContainer()->get(PlanningEventAlertRepository::class);
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

    public function testAAlertThatIsDueIsSentAndStampedOnce(): void
    {
        $alert = $this->alert('2026-08-23 14:00', 30);
        $now = new DateTimeImmutable('2026-08-23 13:30');

        self::assertSame(1, $this->notifier->sendDue($now));
        self::assertNotNull($alert->getSentAt());

        // The second run is the one that matters. Nothing changed between them
        // except the stamp, so a alert going out twice here is the bug the
        // reader reports as "it told me twice".
        self::assertSame(0, $this->notifier->sendDue($now->modify('+1 minute')));
    }

    public function testAAlertNotYetDueIsLeftAlone(): void
    {
        $alert = $this->alert('2026-08-23 14:00', 30);

        self::assertSame(0, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 13:29')));
        self::assertNull($alert->getSentAt());
    }

    /**
     * A worker stopped for an hour comes back to an hour of alerts.
     *
     * `findDue` has no lower bound on lateness for this: a alert arriving late
     * is information, and one that never arrives is a bug. The alternative -
     * skipping anything older than a few minutes - would silently drop every
     * alert of a deploy window.
     */
    public function testALateWorkerStillSends(): void
    {
        $alert = $this->alert('2026-08-23 14:00', 30);

        self::assertSame(1, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 15:45')));
        self::assertNotNull($alert->getSentAt());
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
        $due = $this->alert('2026-08-23 14:00', 30);
        $later = $this->alert('2026-08-23 18:00', 30);
        $alreadySent = $this->alert('2026-08-23 12:00', 30);
        $alreadySent->markSent(new DateTimeImmutable('2026-08-23 11:30'));
        $this->entityManager->flush();

        $found = [];
        foreach ($this->alerts->findDue(new DateTimeImmutable('2026-08-23 13:30')) as $alert) {
            $found[] = $alert->getId();
        }

        self::assertContains($due->getId(), $found);
        self::assertNotContains($later->getId(), $found);
        self::assertNotContains($alreadySent->getId(), $found);
    }

    private function alert(string $start, int $minutesBefore): PlanningEventAlert
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

        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore($minutesBefore);
        $event->addAlert($alert);
        $this->entityManager->persist($alert);

        $this->entityManager->flush();

        $this->created[] = [Planning::class, (int) $planning->getId()];
        $this->created[] = [PlanningEvent::class, (int) $event->getId()];

        return $alert;
    }
}
