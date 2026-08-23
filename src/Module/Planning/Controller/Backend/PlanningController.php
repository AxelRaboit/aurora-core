<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Planning\Event\Dto\PlanningEventInputFactoryInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Manager\PlanningEventManagerInterface;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Event\Serializer\PlanningEventSerializer;
use Aurora\Module\Planning\Planning\Dto\PlanningInputFactoryInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Manager\PlanningManagerInterface;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\Planning\Serializer\PlanningSerializer;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly PlanningEventRepository $eventRepository,
        private readonly PlanningSerializer $planningSerializer,
        private readonly PlanningEventSerializer $eventSerializer,
        private readonly PlanningManagerInterface $planningManager,
        private readonly PlanningEventManagerInterface $eventManager,
        private readonly PlanningInputFactoryInterface $planningInputFactory,
        private readonly PlanningEventInputFactoryInterface $eventInputFactory,
        private readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('/calendar', name: '_calendar', methods: [HttpMethodEnum::Get->value])]
    public function calendar(): Response
    {
        return $this->render('@Planning/backend/index.html.twig', [
            'calendars' => $this->planningSerializer->serializeMany($this->visibleCalendars()),
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

        return $this->json([
            'events' => $this->eventSerializer->serializeMany($this->eventRepository->findInWindow($ids, $from, $to)),
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

        $input = $this->eventInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $planning = $this->writableCalendar($input->getPlanningId());
        if (!$planning instanceof PlanningInterface) {
            return $this->jsonInvalidInput(['planningId' => 'backend.plannings.events.errors.calendar_required']);
        }

        $this->eventManager->update($event, $input, $planning);

        return $this->jsonSuccess(['event' => $this->eventSerializer->serialize($event)]);
    }

    #[Route('/events/{id}/delete', name: '_events_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('planning.events.delete')]
    public function deleteEvent(PlanningEvent $event): JsonResponse
    {
        if ($event->isFromModule()) {
            return $this->jsonInvalidInput(['event' => 'backend.plannings.events.errors.read_only']);
        }

        $this->eventManager->delete($event);

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
    private function writableCalendar(int $id): ?PlanningInterface
    {
        foreach ($this->visibleCalendars() as $planning) {
            if ($planning->getId() === $id) {
                return $planning;
            }
        }

        return null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
