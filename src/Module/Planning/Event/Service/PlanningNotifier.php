<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlertInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Enum\PlanningAlertChannelEnum;
use Aurora\Module\Planning\Event\Repository\PlanningEventAlertRepository;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use Aurora\Module\Planning\Reminder\Repository\PlanningReminderRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns due alerts into notifications.
 *
 * They land on the backend's existing notification list rather than a channel of
 * their own. A calendar alert is the same kind of thing as "a comment needs
 * moderation" - something that happened which you may want to look at - and a
 * second inbox would mean a second bell for the reader to remember to check.
 */
final readonly class PlanningNotifier
{
    public function __construct(
        private PlanningEventAlertRepository $alerts,
        private PlanningReminderRepository $reminders,
        private NotificationManagerInterface $notifications,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private MailService $mail,
    ) {}

    /**
     * Sends what is due and returns how many went out.
     *
     * Marked sent **before** the flush and one flush for the batch: a alert
     * sent twice is worse than one sent late, and a crash between the
     * notification and the mark would do exactly that on the next minute's run.
     */
    public function sendDue(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();

        // Two queries, one flush. They are separate tables and separate
        // questions, but a single flush means a crash cannot leave one kind
        // stamped and the other not.
        $sent = $this->sendDueAlerts($now) + $this->sendDueReminders($now);

        $this->entityManager->flush();

        return $sent;
    }

    /**
     * The reminders that are due, announced at their due time.
     *
     * At the time itself and not before it, which is what separates the two
     * kinds: an event needs warning because you have to be somewhere, and a
     * reminder is the thing itself arriving.
     *
     * Completed ones are excluded by the query, not skipped here - ticking
     * something off has to stop it arriving, and a filter in PHP would still
     * stamp it.
     */
    private function sendDueReminders(DateTimeImmutable $now): int
    {
        $sent = 0;

        foreach ($this->reminders->findDue($now) as $reminder) {
            $recipient = $reminder->getPlanning()->getOwner();

            if ($recipient instanceof CoreUserInterface) {
                $this->deliverReminder($recipient, $reminder);
                ++$sent;
            }

            $reminder->markNotified($now);
        }

        return $sent;
    }

    private function notifyReminder(CoreUserInterface $recipient, PlanningReminderInterface $reminder): void
    {
        $this->notifications->notify(
            $recipient,
            'planning.reminder',
            $reminder->getTitle(),
            $this->translator->trans('backend.plannings.reminders.notification', [
                '%calendar%' => $reminder->getPlanning()->getName(),
            ]),
            $this->urlGenerator->generate(
                'backend_planning_calendar',
                ['view' => 'day', 'date' => $reminder->getDueAt()->format('Y-m-d')],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            ['reminderId' => $reminder->getId()],
        );
    }

    private function sendDueAlerts(DateTimeImmutable $now): int
    {
        $sent = 0;

        foreach ($this->alerts->findDue($now) as $alert) {
            $recipient = $alert->getEvent()->getPlanning()->getOwner();

            // A calendar with no owner has nobody to tell. Marked sent anyway,
            // or the worker picks it up again every minute for ever.
            if ($recipient instanceof CoreUserInterface) {
                $this->deliverAlert($recipient, $alert);
                ++$sent;
            }

            $alert->markSent($now);
        }

        return $sent;
    }

    /**
     * The start, as the reader's calendar shows it.
     *
     * Stored instants are UTC, so formatting one straight out of the entity
     * would tell somebody in Paris their 10:00 meeting starts at 08:00 - and a
     * alert that names the wrong time is worse than no alert. This is a
     * notification and not a screen, so there is no browser to do the
     * conversion: the calendar's own timezone is the closest thing to the
     * reader's, and it is the timezone they set when they made it.
     */
    private function localTime(PlanningEventInterface $event): string
    {
        $planning = $event->getPlanning();

        try {
            $zone = new DateTimeZone($planning->getTimezone());
        } catch (Exception) {
            $zone = new DateTimeZone('UTC');
        }

        return $event->getStartAt()->setTimezone($zone)->format('H:i');
    }

    /**
     * Sends one alert on the channel it was set for.
     *
     * Counted as sent either way, and stamped either way: the worker's job is to
     * have tried once, and a channel that failed is the mailer's problem to report
     * rather than a reason to try again every minute for ever.
     */
    private function deliverAlert(CoreUserInterface $recipient, PlanningEventAlertInterface $alert): void
    {
        if (PlanningAlertChannelEnum::Email === $alert->getChannel()) {
            $this->emailAlert($recipient, $alert);

            return;
        }

        $this->notify($recipient, $alert);
    }

    private function deliverReminder(CoreUserInterface $recipient, PlanningReminderInterface $reminder): void
    {
        if (PlanningAlertChannelEnum::Email === $reminder->getChannel()) {
            $this->emailReminder($recipient, $reminder);

            return;
        }

        $this->notifyReminder($recipient, $reminder);
    }

    private function emailAlert(CoreUserInterface $recipient, PlanningEventAlertInterface $alert): void
    {
        $event = $alert->getEvent();

        $this->mail->send(
            $recipient->getEmail(),
            'backend.plannings.mail.alert_subject',
            '@Planning/email/alert.html.twig',
            [
                'title' => $event->getTitle(),
                'calendarName' => $event->getPlanning()->getName(),
                // Formatted here rather than in the template, because the zone is
                // the calendar's and Twig would have to be told which.
                'whenText' => $this->localDateTime($event),
                'location' => $event->getLocation(),
                'description' => $event->getDescription(),
                'url' => $this->dayUrl($event->getStartAt()->format('Y-m-d')),
            ],
            subjectParams: ['%title%' => $event->getTitle()],
        );
    }

    private function emailReminder(CoreUserInterface $recipient, PlanningReminderInterface $reminder): void
    {
        $this->mail->send(
            $recipient->getEmail(),
            'backend.plannings.mail.reminder_subject',
            '@Planning/email/reminder.html.twig',
            [
                'title' => $reminder->getTitle(),
                'calendarName' => $reminder->getPlanning()->getName(),
                'notes' => $reminder->getNotes(),
                'url' => $this->dayUrl($reminder->getDueAt()->format('Y-m-d')),
            ],
            subjectParams: ['%title%' => $reminder->getTitle()],
        );
    }

    private function dayUrl(string $date): string
    {
        return $this->urlGenerator->generate(
            'backend_planning_calendar',
            ['view' => 'day', 'date' => $date],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /** The event's start, spelled out on the calendar's clock. */
    private function localDateTime(PlanningEventInterface $event): string
    {
        $planning = $event->getPlanning();

        try {
            $zone = new DateTimeZone($planning->getTimezone());
        } catch (Exception) {
            $zone = new DateTimeZone('UTC');
        }

        return $event->getStartAt()->setTimezone($zone)->format('d/m/Y H:i');
    }

    private function notify(CoreUserInterface $recipient, PlanningEventAlertInterface $alert): void
    {
        $event = $alert->getEvent();

        $this->notifications->notify(
            $recipient,
            'planning.alert',
            $event->getTitle(),
            $this->translator->trans('backend.plannings.alerts.notification', [
                '%calendar%' => $event->getPlanning()->getName(),
                '%when%' => $this->localTime($event),
            ]),
            $this->urlGenerator->generate(
                'backend_planning_calendar',
                // The day, not the month: a alert is about one event, and the
                // day view is where it is the thing you are looking at rather
                // than one chip among forty.
                ['view' => 'day', 'date' => $event->getStartAt()->format('Y-m-d')],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            ['eventId' => $event->getId(), 'minutesBefore' => $alert->getMinutesBefore()],
        );
    }
}
