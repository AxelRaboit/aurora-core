<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Enum\PlanningAlertChannelEnum;
use Aurora\Module\Planning\Event\Repository\PlanningEventAlertRepository;
use Aurora\Module\Planning\Event\Service\PlanningNotifier;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * When an alert goes out, and when it does not go out again.
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
        // except the stamp, so an alert going out twice here is the bug the
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
     * `findDue` has no lower bound on lateness for this: an alert arriving late
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

    /**
     * The email channel actually sends an email.
     *
     * The channel was stored, offered in the form, mirrored into JavaScript and
     * branched on in the notifier, and nothing anywhere proved that picking it
     * produced a message. A `match` that fell through to the notification arm
     * would have passed every other test in this file.
     */
    public function testAnEmailChannelAlertGoesOutAsMail(): void
    {
        $alert = $this->alert('2026-08-23 14:00', 30, PlanningAlertChannelEnum::Email);

        self::assertSame(1, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 13:30')));
        self::assertNotNull($alert->getSentAt());

        $messages = $this->mailerMessages();
        self::assertCount(1, $messages);
        self::assertSame([$this->admin->getEmail()], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $messages[0]->getTo(),
        ));
        self::assertStringContainsString('Réunion 2026-08-23 14:00', (string) $messages[0]->getSubject());
    }

    /**
     * And the notification channel does not.
     *
     * The pair is the point: one test alone would pass against a notifier that
     * sent mail for everything, or for nothing.
     */
    public function testANotificationChannelAlertSendsNoMail(): void
    {
        $this->alert('2026-08-23 14:00', 30);

        self::assertSame(1, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 13:30')));
        self::assertCount(0, $this->mailerMessages());
    }

    /**
     * The mail names the start on the calendar's clock, not on the column's.
     *
     * Instants are stored UTC, and a screen converts them in the browser. An email
     * has no browser, so the notifier does it from the calendar's timezone - and
     * getting this wrong is the one mistake in the whole feature the reader is
     * guaranteed to notice, because they miss the meeting.
     */
    public function testTheMailSpellsTheTimeInTheCalendarsZone(): void
    {
        $this->alert('2026-08-23 08:00', 15, PlanningAlertChannelEnum::Email, 'Europe/Paris');

        self::assertSame(1, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 07:45')));

        $body = $this->mailerMessages()[0]->getHtmlBody();

        // August, so Paris is UTC+2: the stored 08:00 is a 10:00 meeting.
        self::assertStringContainsString('23/08/2026 10:00', (string) $body);
        self::assertStringNotContainsString('23/08/2026 08:00', (string) $body);
    }

    /** A reminder on the email channel takes the same road. */
    public function testAnEmailChannelReminderGoesOutAsMail(): void
    {
        $planning = new Planning();
        $planning->setName('Rappels par mail');
        $planning->setOwner($this->admin);
        $this->entityManager->persist($planning);

        $reminder = new PlanningReminder();
        $reminder->setPlanning($planning);
        $reminder->setTitle('Renouveler le domaine');
        $reminder->setDueAt(new DateTimeImmutable('2026-08-23 09:00'));
        $reminder->setChannel(PlanningAlertChannelEnum::Email);
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        $this->created[] = [PlanningReminder::class, (int) $reminder->getId()];
        $this->created[] = [Planning::class, (int) $planning->getId()];

        // At the due time, not before it: a reminder is the thing arriving.
        self::assertSame(1, $this->notifier->sendDue(new DateTimeImmutable('2026-08-23 09:00')));

        $messages = $this->mailerMessages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('Renouveler le domaine', (string) $messages[0]->getSubject());
    }

    /**
     * The messages that actually reached a transport, as `Email` objects.
     *
     * Not `getMailerMessages()`, which reports each mail twice here. Messenger is
     * enabled, so Symfony's mailer hands `SendEmailMessage` to the default bus:
     * one `MessageEvent` is dispatched queued at that point, and a second
     * unqueued when the handler runs it through the transport. Counting both
     * would read one email as two - which is what this helper existed to rule
     * out, so it filters instead of asserting a number it does not mean.
     *
     * `getMailerMessages()` also answers `RawMessage`, which has no `getSubject`;
     * every caller wants the parsed one, so the narrowing happens once.
     *
     * @return list<Email>
     */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $event) {
            if ($event->isQueued()) {
                continue;
            }

            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $messages[] = $message;
        }

        return $messages;
    }

    private function alert(
        string $start,
        int $minutesBefore,
        PlanningAlertChannelEnum $channel = PlanningAlertChannelEnum::Notification,
        string $timezone = 'UTC',
    ): PlanningEventAlert {
        $planning = new Planning();
        $planning->setName('Rappels '.$start);
        $planning->setOwner($this->admin);
        $planning->setTimezone($timezone);
        $this->entityManager->persist($planning);

        $startAt = new DateTimeImmutable($start);
        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle('Réunion '.$start);
        $event->setSpan($startAt, $startAt->modify('+1 hour'));
        $this->entityManager->persist($event);

        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore($minutesBefore);
        $alert->setChannel($channel);
        $event->addAlert($alert);
        $this->entityManager->persist($alert);

        $this->entityManager->flush();

        $this->created[] = [Planning::class, (int) $planning->getId()];
        $this->created[] = [PlanningEvent::class, (int) $event->getId()];

        return $alert;
    }
}
