<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventReminderInterface;
use Aurora\Module\Planning\Event\Repository\PlanningEventReminderRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns due reminders into notifications.
 *
 * They land on the backend's existing notification list rather than a channel of
 * their own. A calendar reminder is the same kind of thing as "a comment needs
 * moderation" - something that happened which you may want to look at - and a
 * second inbox would mean a second bell for the reader to remember to check.
 */
final readonly class PlanningReminderNotifier
{
    public function __construct(
        private PlanningEventReminderRepository $reminders,
        private NotificationManagerInterface $notifications,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Sends what is due and returns how many went out.
     *
     * Marked sent **before** the flush and one flush for the batch: a reminder
     * sent twice is worse than one sent late, and a crash between the
     * notification and the mark would do exactly that on the next minute's run.
     */
    public function sendDue(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $sent = 0;

        foreach ($this->reminders->findDue($now) as $reminder) {
            $recipient = $reminder->getEvent()->getPlanning()->getOwner();

            // A calendar with no owner has nobody to tell. Marked sent anyway,
            // or the worker picks it up again every minute for ever.
            if ($recipient instanceof CoreUserInterface) {
                $this->notify($recipient, $reminder);
                ++$sent;
            }

            $reminder->markSent($now);
        }

        $this->entityManager->flush();

        return $sent;
    }

    /**
     * The start, as the reader's calendar shows it.
     *
     * Stored instants are UTC, so formatting one straight out of the entity
     * would tell somebody in Paris their 10:00 meeting starts at 08:00 - and a
     * reminder that names the wrong time is worse than no reminder. This is a
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

    private function notify(CoreUserInterface $recipient, PlanningEventReminderInterface $reminder): void
    {
        $event = $reminder->getEvent();

        $this->notifications->notify(
            $recipient,
            'planning.reminder',
            $event->getTitle(),
            $this->translator->trans('backend.plannings.reminders.notification', [
                '%calendar%' => $event->getPlanning()->getName(),
                '%when%' => $this->localTime($event),
            ]),
            $this->urlGenerator->generate(
                'backend_planning_calendar',
                ['month' => $event->getStartAt()->format('Y-m')],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            ['eventId' => $event->getId(), 'minutesBefore' => $reminder->getMinutesBefore()],
        );
    }
}
