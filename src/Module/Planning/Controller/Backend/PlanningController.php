<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Dto\PlanningEventInputFactoryInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Manager\PlanningEventManagerInterface;
use Aurora\Module\Planning\Event\Serializer\PlanningEventSerializer;
use Aurora\Module\Planning\Planning\Dto\PlanningInputFactoryInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Manager\PlanningManagerInterface;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\Planning\Serializer\PlanningSerializer;
use Aurora\Module\Planning\Recurrence\PlanningOccurrenceFinder;
use Aurora\Module\Planning\Recurrence\RecurrenceScopeEnum;
use Aurora\Module\Planning\Reminder\Dto\PlanningReminderInputFactoryInterface;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Planning\Reminder\Manager\PlanningReminderManagerInterface;
use Aurora\Module\Planning\Reminder\Repository\PlanningReminderRepository;
use Aurora\Module\Planning\Reminder\Serializer\PlanningReminderSerializer;
use Aurora\Module\Planning\Share\Entity\PlanningShare;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The calendar's screen and its API.
 *
 * One controller for both the calendars and their events, unlike the Ged module
 * which has one per entity: a calendar has no page of its own here, it is a
 * sidebar on the events screen, and splitting them would put two controllers
 * behind one view.
 */
#[Route('/backend/planning', name: 'backend_planning')]
#[IsGranted('planning.calendars.view')]
final class PlanningController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningOccurrenceFinder $occurrences,
        private readonly PlanningSerializer $planningSerializer,
        private readonly PlanningEventSerializer $eventSerializer,
        private readonly PlanningManagerInterface $planningManager,
        private readonly PlanningEventManagerInterface $eventManager,
        private readonly PlanningInputFactoryInterface $planningInputFactory,
        private readonly PlanningEventInputFactoryInterface $eventInputFactory,
        private readonly PlanningReminderRepository $reminderRepository,
        private readonly PlanningReminderSerializer $reminderSerializer,
        private readonly PlanningReminderManagerInterface $reminderManager,
        private readonly PlanningReminderInputFactoryInterface $reminderInputFactory,
        private readonly PayloadValidator $payloadValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
    ) {}

    #[Route('/calendar', name: '_calendar', methods: [HttpMethodEnum::Get->value])]
    public function calendar(): Response
    {
        return $this->render('@Planning/backend/index.html.twig', [
            'calendars' => $this->planningSerializer->serializeMany($this->visibleCalendars()),
            // The zones the runtime can actually resolve, which is what the DTO
            // validates against. A list of our own would drift from PHP's, and a
            // calendar cutting its days in a zone the runtime cannot resolve puts
            // every all-day event on the wrong day.
            'timezones' => DateTimeZone::listIdentifiers(),
            // Who can be invited: the accounts that can reach the backend at all.
            // A front-office account has no calendar to be invited into.
            'people' => $this->invitablePeople(),
            // So the screen can tell which attendee is the reader, and offer them
            // the three buttons rather than everybody's.
            'currentUserId' => $this->getUser() instanceof CoreUserInterface ? $this->getUser()->getId() : null,
        ]);
    }

    /**
     * The events in one window, for the calendars the reader may see.
     *
     * The window comes from the query rather than being worked out here: the
     * screen knows which month it is drawing, including the days it shows from
     * the months either side, and a server guessing "the current month" would
     * leave those trailing cells empty.
     */
    #[Route('/events', name: '_events', methods: [HttpMethodEnum::Get->value])]
    public function events(Request $request): JsonResponse
    {
        $from = $this->date($request->query->get('from'));
        $to = $this->date($request->query->get('to'));

        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || $to <= $from) {
            return $this->jsonInvalidInput(['window' => 'backend.plannings.errors.window_invalid']);
        }

        $ids = array_map(
            static fn (PlanningInterface $planning): int => (int) $planning->getId(),
            $this->visibleCalendars(),
        );

        // Both kinds in one response, because the screen asks for one window and
        // draws both in the same grid. Two endpoints would be two round trips
        // whose results have to arrive together to be drawn at all.
        return $this->json([
            'events' => $this->eventSerializer->serializeMany($this->occurrences->find($ids, $from, $to)),
            'reminders' => $this->reminderSerializer->serializeMany($this->reminderRepository->findInWindow($ids, $from, $to)),
        ]);
    }

    #[Route('/calendars/create', name: '_calendars_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.calendars.manage')]
    public function createCalendar(Request $request): JsonResponse
    {
        $input = $this->planningInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        /** @var User $user */
        $user = $this->getUser();

        return $this->jsonSuccess([
            'calendar' => $this->planningSerializer->serialize($this->planningManager->create($input, $user)),
        ]);
    }

    #[Route('/calendars/{id}/update', name: '_calendars_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.calendars.manage')]
    public function updateCalendar(Planning $planning, Request $request): JsonResponse
    {
        $input = $this->planningInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->planningManager->update($planning, $input);

        return $this->jsonSuccess(['calendar' => $this->planningSerializer->serialize($planning)]);
    }

    /**
     * Publishes a feed for this calendar, or replaces the address if one exists.
     *
     * One route for both, because they are the same request: asking to publish
     * again is how somebody revokes an address they shared too widely, and a
     * separate "rotate" would be a second name for it.
     *
     * The URL comes back absolute. A relative one would be useless - it is meant
     * to be pasted into a phone.
     */
    /**
     * Sets who a calendar is shared with, by name.
     *
     * The whole list every time, like the alerts and the attendees: the form shows
     * all of it at once, so a diff computed here would only be a diff the client
     * already made.
     *
     * Only the owner may change it. Somebody granted write access can put things on
     * a calendar; deciding who else gets in is not the same authority, and treating
     * it as one would let a guest hand out keys.
     */
    #[Route('/calendars/{id}/shares', name: '_calendars_shares', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.calendars.manage')]
    public function setShares(Planning $planning, Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof CoreUserInterface || $planning->getOwner()?->getId() !== $user->getId()) {
            return $this->jsonInvalidInput(['shares' => 'backend.plannings.shares.errors.owner_only']);
        }

        $wanted = [];
        foreach ((array) ($this->decodeJson($request)['shares'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!is_numeric($row['userId'] ?? null)) {
                continue;
            }
            $wanted[(int) $row['userId']] = (bool) ($row['canWrite'] ?? false);
        }

        // The owner is never in the list: they already have every right this table
        // can grant, and a row saying so would be a row somebody could delete.
        unset($wanted[(int) $user->getId()]);

        foreach ($planning->getShares() as $existing) {
            $id = (int) $existing->getUser()->getId();

            if (!array_key_exists($id, $wanted)) {
                $planning->removeShare($existing);
                $this->entityManager->remove($existing);

                continue;
            }

            // Kept, so a change of level is an update rather than a delete and an
            // insert - which the unique index would refuse in that order anyway.
            $existing->setCanWrite($wanted[$id]);
            unset($wanted[$id]);
        }

        foreach ($wanted as $id => $canWrite) {
            $person = $this->users->find($id);
            if (!$person instanceof CoreUserInterface) {
                continue;
            }

            $share = new PlanningShare();
            $share->setUser($person);
            $share->setCanWrite($canWrite);
            $planning->addShare($share);
            $this->entityManager->persist($share);
        }

        $this->entityManager->flush();

        return $this->jsonSuccess(['calendar' => $this->planningSerializer->serialize($planning)]);
    }

    #[Route('/calendars/{id}/feed', name: '_calendars_feed', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.calendars.manage')]
    public function publishFeed(Planning $planning): JsonResponse
    {
        if (!$this->writableCalendar((int) $planning->getId()) instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $planning->publishFeed();
        $this->entityManager->flush();

        return $this->jsonSuccess([
            'calendar' => $this->planningSerializer->serialize($planning),
            'feedUrl' => $this->feedUrl($planning),
        ]);
    }

    #[Route('/calendars/{id}/feed/revoke', name: '_calendars_feed_revoke', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.calendars.manage')]
    public function revokeFeed(Planning $planning): JsonResponse
    {
        if (!$this->writableCalendar((int) $planning->getId()) instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $planning->revokeFeed();
        $this->entityManager->flush();

        return $this->jsonSuccess(['calendar' => $this->planningSerializer->serialize($planning)]);
    }

    #[Route('/calendars/{id}/delete', name: '_calendars_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.calendars.manage')]
    public function deleteCalendar(Planning $planning): JsonResponse
    {
        $this->planningManager->delete($planning);

        return $this->jsonSuccess();
    }

    #[Route('/events/create', name: '_events_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.create')]
    public function createEvent(Request $request): JsonResponse
    {
        $input = $this->eventInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $planning = $this->writableCalendar($input->getPlanningId());
        if (!$planning instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        return $this->jsonSuccess([
            'event' => $this->eventSerializer->serialize($this->eventManager->create($input, $planning)),
        ]);
    }

    #[Route('/events/{id}/update', name: '_events_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.edit')]
    public function updateEvent(PlanningEvent $event, Request $request): JsonResponse
    {
        // Answered before validating the payload: an event a module owns is not
        // editable whatever the body says, and reporting field errors on a form
        // that cannot be submitted anyway is a worse answer than the truth.
        if ($event->isFromModule()) {
            return $this->jsonInvalidInput(['event' => 'backend.plannings.events.errors.read_only']);
        }

        // Kept, because the scope travels in the same body as the fields and the
        // factory only reads the ones it knows.
        $data = $this->decodeJson($request);
        $input = $this->eventInputFactory->fromArray($data);
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $planning = $this->writableCalendar($input->getPlanningId());
        if (!$planning instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $scope = RecurrenceScopeEnum::fromRequest($data['scope'] ?? null);
        $occurrenceAt = $this->date($data['occurrenceAt'] ?? null);

        if ($scope->needsOccurrence() && !$occurrenceAt instanceof DateTimeImmutable) {
            return $this->jsonInvalidInput(['occurrenceAt' => 'backend.plannings.events.errors.occurrence_required']);
        }

        $written = $this->eventManager->updateAtScope($event, $input, $planning, $scope, $occurrenceAt);

        return $this->jsonSuccess(['event' => $this->eventSerializer->serialize($written)]);
    }

    /**
     * Dragging or resizing an event: two instants and nothing else.
     *
     * Its own route because a drag knows the span and not the rest of the event,
     * and posting a whole event the grid does not hold would be a way to lose a
     * field. Validated here rather than left to the entity: it throws on an end
     * before a start, and a 500 is the wrong answer to a gesture.
     */
    /**
     * An attendee answering for themselves.
     *
     * Not gated on `planning.events.edit`: answering an invitation is not editing
     * the event, and an attendee who cannot edit somebody else's calendar still has
     * to be able to say whether they are coming. Gated on being invited instead,
     * which is the only authority that matters here.
     */
    #[Route('/events/{id}/respond', name: '_events_respond', methods: [HttpMethodEnum::Post->value])]
    public function respondToEvent(PlanningEvent $event, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof CoreUserInterface) {
            return $this->jsonInvalidInput(['status' => 'backend.plannings.attendees.errors.not_invited']);
        }

        $mine = null;
        foreach ($event->getAttendees() as $attendee) {
            if ($attendee->getUser()->getId() === $user->getId()) {
                $mine = $attendee;
            }
        }

        // Refused rather than created: answering an invitation you were not sent
        // would be a way to add yourself to somebody's meeting.
        if (null === $mine) {
            return $this->jsonInvalidInput(['status' => 'backend.plannings.attendees.errors.not_invited']);
        }

        $status = PlanningAttendeeStatusEnum::tryFrom((string) ($this->decodeJson($request)['status'] ?? ''));
        if (null === $status) {
            return $this->jsonInvalidInput(['status' => 'backend.plannings.attendees.errors.status_unknown']);
        }

        $mine->respond($status, new DateTimeImmutable());
        $this->entityManager->flush();

        return $this->jsonSuccess(['event' => $this->eventSerializer->serialize($event)]);
    }

    #[Route('/events/{id}/move', name: '_events_move', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.edit')]
    public function moveEvent(PlanningEvent $event, Request $request): JsonResponse
    {
        if ($event->isFromModule()) {
            return $this->jsonInvalidInput(['event' => 'backend.plannings.events.errors.read_only']);
        }

        if (!$this->writableCalendar((int) $event->getPlanning()->getId()) instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $data = $this->decodeJson($request);
        $startAt = $this->date($data['startAt'] ?? null);
        $endAt = $this->date($data['endAt'] ?? null);

        if (!$startAt instanceof DateTimeImmutable) {
            return $this->jsonInvalidInput(['startAt' => 'backend.plannings.events.errors.start_required']);
        }

        if (!$endAt instanceof DateTimeImmutable) {
            return $this->jsonInvalidInput(['endAt' => 'backend.plannings.events.errors.end_required']);
        }

        if ($endAt < $startAt) {
            return $this->jsonInvalidInput(['endAt' => 'backend.plannings.events.errors.end_before_start']);
        }

        $scope = RecurrenceScopeEnum::fromRequest($data['scope'] ?? null);
        $occurrenceAt = $this->date($data['occurrenceAt'] ?? null);

        if ($scope->needsOccurrence() && !$occurrenceAt instanceof DateTimeImmutable) {
            return $this->jsonInvalidInput(['occurrenceAt' => 'backend.plannings.events.errors.occurrence_required']);
        }

        $written = $this->eventManager->moveAtScope($event, $startAt, $endAt, $scope, $occurrenceAt);

        return $this->jsonSuccess(['event' => $this->eventSerializer->serialize($written)]);
    }

    #[Route('/events/{id}/delete', name: '_events_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.delete')]
    public function deleteEvent(PlanningEvent $event, Request $request): JsonResponse
    {
        if ($event->isFromModule()) {
            return $this->jsonInvalidInput(['event' => 'backend.plannings.events.errors.read_only']);
        }

        $data = $this->decodeJson($request);
        $scope = RecurrenceScopeEnum::fromRequest($data['scope'] ?? null);
        $occurrenceAt = $this->date($data['occurrenceAt'] ?? null);

        if ($scope->needsOccurrence() && !$occurrenceAt instanceof DateTimeImmutable) {
            return $this->jsonInvalidInput(['occurrenceAt' => 'backend.plannings.events.errors.occurrence_required']);
        }

        $this->eventManager->deleteAtScope($event, $scope, $occurrenceAt);

        return $this->jsonSuccess();
    }

    /**
     * @return list<PlanningInterface>
     */
    private function visibleCalendars(): array
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->planningRepository->findVisibleTo($user);
    }

    /**
     * The calendar an event may be written to, or null.
     *
     * Resolved through the visible list rather than by a bare `find()`: an id
     * arriving in a payload is a claim, and a reader who cannot see a calendar
     * must not be able to drop an event into it.
     */
    /**
     * The calendar this id names, if the reader may write into it.
     *
     * Two questions and not one, which it used to conflate: seeing a calendar and
     * writing into it became different the moment a calendar could be shared
     * read-only. Resolved through the visible list first, so an id naming a
     * calendar nobody can see is answered the same way as one naming nothing -
     * saying "you cannot write to that one" would confirm it exists.
     */
    private function writableCalendar(int $id): ?PlanningInterface
    {
        $user = $this->getUser();
        if (!$user instanceof CoreUserInterface) {
            return null;
        }

        foreach ($this->visibleCalendars() as $planning) {
            if ($planning->getId() === $id) {
                return $planning->isWritableBy($user) ? $planning : null;
            }
        }

        return null;
    }

    /**
     * The reminder routes mirror the event ones, and reuse their privileges.
     *
     * Deliberately not `planning.reminders.*`: the authority is the same one -
     * whether you may put things on a calendar you can see - and three more
     * near-identical permissions would be three more rows nobody configures
     * differently. The labels say "events and reminders" so the screen does not
     * promise something narrower than it grants.
     */
    #[Route('/reminders/create', name: '_reminders_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.create')]
    public function createReminder(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $input = $this->reminderInputFactory->fromArray($data);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $planning = $this->writableCalendar($input->getPlanningId());
        if (!$planning instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        return $this->jsonSuccess([
            'reminder' => $this->reminderSerializer->serialize($this->reminderManager->create($input, $planning)),
        ]);
    }

    #[Route('/reminders/{id}/update', name: '_reminders_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.edit')]
    public function updateReminder(PlanningReminder $reminder, Request $request): JsonResponse
    {
        if (!$this->canReach($reminder)) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $input = $this->reminderInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $planning = $this->writableCalendar($input->getPlanningId());
        if (!$planning instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $this->reminderManager->update($reminder, $input, $planning);

        return $this->jsonSuccess(['reminder' => $this->reminderSerializer->serialize($reminder)]);
    }

    #[Route('/reminders/{id}/delete', name: '_reminders_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.delete')]
    public function deleteReminder(PlanningReminder $reminder): JsonResponse
    {
        if (!$this->canReach($reminder)) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $this->reminderManager->delete($reminder);

        return $this->jsonSuccess();
    }

    /**
     * Ticking one off, which is an edit and not a deletion.
     *
     * Its own route rather than a field on update, because the grid's checkbox
     * has nothing else to send - making it post a whole reminder would mean the
     * grid holding every field of every row just to be able to tick one.
     */
    #[Route('/reminders/{id}/toggle', name: '_reminders_toggle', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.edit')]
    public function toggleReminder(PlanningReminder $reminder): JsonResponse
    {
        if (!$this->canReach($reminder)) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $this->reminderManager->toggle($reminder);

        return $this->jsonSuccess(['reminder' => $this->reminderSerializer->serialize($reminder)]);
    }

    /**
     * Whether the reader may act on this reminder at all.
     *
     * An id in a URL is a claim, the same way an id in a payload is: a reminder on
     * a calendar nobody can see must not be reachable by guessing its number.
     */
    private function canReach(PlanningReminder $reminder): bool
    {
        return $this->writableCalendar((int) $reminder->getPlanning()->getId()) instanceof PlanningInterface;
    }

    /**
     * The accounts that can be invited, as the picker wants them.
     *
     * Backend accounts only. A front-office account has no calendar to be invited
     * into, and offering one would be an invitation nobody can answer.
     *
     * @return list<array{value: int, label: string}>
     */
    private function invitablePeople(): array
    {
        $people = [];
        foreach ($this->users->findBy(['type' => UserTypeEnum::Backend->value], ['name' => 'ASC']) as $user) {
            $people[] = ['value' => (int) $user->getId(), 'label' => $user->getName()];
        }

        return $people;
    }

    private function feedUrl(Planning $planning): ?string
    {
        $token = $planning->getFeedToken();

        return null === $token
            ? null
            : $this->generateUrl('planning_feed_show', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * An instant, in UTC, or null.
     *
     * The conversion is the point, and it was missing here for as long as the only
     * caller sent `Z`-suffixed window bounds - where converting is a no-op. The
     * moment `occurrenceAt` arrived with a `+02:00` offset, the value went into a
     * column with no timezone as its own wall clock and came back two hours off,
     * so a detached occurrence never matched the date it was meant to replace and
     * the series drew it twice.
     *
     * Same rule as the input factory's, and stated in both places because that is
     * where the two entry points are: every instant in this module's tables is
     * UTC.
     */
    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
