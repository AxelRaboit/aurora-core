<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlertInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Repository\PlanningEventAlertRepository;
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
        private NotificationManagerInterface $notifications,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
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
        $sent = 0;

        foreach ($this->alerts->findDue($now) as $alert) {
            $recipient = $alert->getEvent()->getPlanning()->getOwner();

            // A calendar with no owner has nobody to tell. Marked sent anyway,
            // or the worker picks it up again every minute for ever.
            if ($recipient instanceof CoreUserInterface) {
                $this->notify($recipient, $alert);
                ++$sent;
            }

            $alert->markSent($now);
        }

        $this->entityManager->flush();

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
