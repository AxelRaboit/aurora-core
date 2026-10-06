<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Calendar\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\Calendar\View\StudioCalendarViewBuilder;
use Aurora\Module\Studio\CustomerSpace\Enum\SpaceScopeEnum;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use function in_array;

/**
 * The editorial calendar: every client's publications on one month.
 *
 * The dashboard says what is waiting; this page plans. Behind the same right
 * as the spaces themselves, and never wider than the spaces the reader may
 * see.
 */
#[Route(name: 'suite_studio_')]
#[IsGranted('studio.spaces.view')]
final class StudioCalendarController extends AbstractController
{
    use JsonResponseTrait;

    /** The states `SpaceWorkload::statesOf()` names. */
    private const array STATES = ['upcoming', 'with_client', 'late_review', 'changes_requested', 'missed', 'published'];

    public function __construct(
        private readonly StudioCalendarViewBuilder $viewBuilder,
    ) {}

    #[Route('/suite/studio/spaces/calendar', name: 'spaces_calendar', methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function index(Request $request): Response
    {
        return $this->render('@Studio/suite/calendar/index.html.twig', $this->viewBuilder->indexView(SpaceScopeEnum::fromRequest($request->query->get('scope'))));
    }

    /**
     * The address the calendar had while it was a menu entry of its own. Kept
     * as a redirect, query included: bookmarks and links in old mails still
     * land on the month they asked for.
     */
    #[Route('/suite/studio/calendar', name: 'calendar_legacy', methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('suite_studio_spaces_calendar', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/suite/studio/spaces/calendar/items', name: 'spaces_calendar_items', methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function items(Request $request): JsonResponse
    {
        $scope = SpaceScopeEnum::fromRequest($request->query->get('scope'));
        $state = $request->query->getString('state');

        // One state, every month: what a tile of the dashboard opens.
        if (in_array($state, self::STATES, true) && '' === $request->query->getString('from')) {
            return $this->jsonSuccess(['items' => $this->viewBuilder->itemsInState($scope, $state)]);
        }

        $from = $this->instant($request->query->getString('from'));
        $to = $this->instant($request->query->getString('to'));
        $items = !$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable
            ? null
            : $this->viewBuilder->items($scope, $from, $to);

        if (null === $items) {
            return $this->jsonInvalidInput(['window' => 'suite.studio.calendar.errors.window']);
        }

        return $this->jsonSuccess(['items' => $items]);
    }

    private function instant(string $value): ?DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
