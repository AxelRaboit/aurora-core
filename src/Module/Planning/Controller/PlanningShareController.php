<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Module\Planning\Event\Serializer\PlanningEventSerializer;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Link\Manager\PlanningShareLinkManagerInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\PlanningContext;
use Aurora\Module\Planning\Recurrence\PlanningOccurrenceFinder;
use Aurora\Module\Planning\Reminder\Repository\PlanningReminderRepository;
use Aurora\Module\Planning\Reminder\Serializer\PlanningReminderSerializer;
use Aurora\Module\Planning\Time\PlanningClock;
use Aurora\Module\Planning\View\PlanningShareViewBuilder;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;

/**
 * A calendar, read by somebody with no account.
 *
 * **Deliberately unauthenticated**, on the same terms as the `.ics` feed next
 * door: the URL is the credential, it is opt-in, it is 32 random bytes, and it can
 * be revoked or left to expire. Outside `/backend` so the firewall's public
 * fallback applies rather than a hole punched in the admin rules - an exception
 * there is read by everyone who audits that file.
 *
 * **One answer for every way a link can fail.** Unknown, expired, revoked and
 * minted-for-a-feed all render the same page with the same status. Telling them
 * apart would confirm which random strings had once been real, and the reader's
 * question is only ever whether they can still use it.
 *
 * Read-only, and in two independent ways: the payload marks every event
 * `readOnly` so no grid offers to drag one, and there is no write route here to
 * reach. The first is courtesy, the second is the guarantee.
 */
#[Route('/planning/share', name: 'planning_share')]
final class PlanningShareController extends AbstractController
{
    public function __construct(
        private readonly PlanningShareLinkManagerInterface $shareLinks,
        private readonly PlanningShareViewBuilder $viewBuilder,
        private readonly PlanningOccurrenceFinder $occurrences,
        private readonly PlanningEventSerializer $eventSerializer,
        private readonly PlanningReminderRepository $reminders,
        private readonly PlanningReminderSerializer $reminderSerializer,
        private readonly PlanningContext $planningContext,
    ) {}

    /**
     * The token's alphabet is constrained in the route, so a path carrying
     * anything else never reaches a query. Wide enough to cover both the hex
     * tokens minted now and the base64url ones carried over from `feed_token`.
     */
    #[Route(
        '/{token}',
        name: '_show',
        requirements: ['token' => '[A-Za-z0-9_-]{20,64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $token): Response
    {
        $link = $this->resolve($token);

        if (!$link instanceof PlanningShareLinkInterface) {
            return $this->unavailable();
        }

        return $this->render('@Planning/share/show.html.twig', $this->viewBuilder->pageView($link));
    }

    /**
     * The events in a window, for the grid the page mounted.
     *
     * Scoped to the link's own calendars and nothing else: the id list comes from
     * the link, never from the request, so a guest cannot widen their own view by
     * asking for another calendar's id.
     */
    #[Route(
        '/{token}/events',
        name: '_events',
        requirements: ['token' => '[A-Za-z0-9_-]{20,64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function events(string $token, Request $request): JsonResponse
    {
        $link = $this->resolve($token);

        if (!$link instanceof PlanningShareLinkInterface) {
            // JSON here rather than the page: this is fetched by the grid, and a
            // rendered page would arrive as unparseable noise.
            return new JsonResponse(['events' => [], 'reminders' => []], HttpStatusEnum::NotFound->value);
        }

        $from = PlanningClock::utc($request->query->get('from'));
        $to = PlanningClock::utc($request->query->get('to'));

        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || $to <= $from) {
            return new JsonResponse(['events' => [], 'reminders' => []], HttpStatusEnum::UnprocessableEntity->value);
        }

        $ids = array_map(
            static fn (PlanningInterface $planning): int => (int) $planning->getId(),
            $link->getCalendars()->toArray(),
        );

        $response = new JsonResponse($this->viewBuilder->windowView(
            $this->eventSerializer->serializeMany($this->occurrences->find($ids, $from, $to)),
            $this->reminderSerializer->serializeMany($this->reminders->findInWindow($ids, $from, $to)),
        ));

        // The URL is a secret, so nothing in between may keep the answer.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * A `Web` link that still works, or null.
     *
     * The mode is part of the question: a token minted as a feed must not open a
     * page, or the address somebody put in a phone becomes a readable schedule for
     * anyone who finds it in a browser history.
     */
    private function resolve(string $token): ?PlanningShareLinkInterface
    {
        if (!$this->planningContext->isBackendEnabled()) {
            return null;
        }

        return $this->shareLinks->resolveUsable($token, PlanningShareLinkModeEnum::Web);
    }

    /**
     * 404, with something a person can read.
     *
     * The status is the honest one - there is nothing at this address - and the
     * body exists because a guest who was sent a link that has since closed
     * deserves a sentence rather than a browser error page.
     */
    private function unavailable(): Response
    {
        $response = $this->render('@Planning/share/unavailable.html.twig');
        $response->setStatusCode(HttpStatusEnum::NotFound->value);

        return $response;
    }
}
