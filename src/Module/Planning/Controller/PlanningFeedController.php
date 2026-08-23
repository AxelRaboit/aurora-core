<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Planning\Feed\IcalWriter;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\PlanningContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A published calendar, as an iCalendar feed.
 *
 * **Deliberately unauthenticated**, because a phone's calendar app fetches this on
 * a timer with no session. The URL is the credential, which is the same model as
 * Google's "secret address in iCal format", and it puts the whole weight on three
 * properties: publishing is opt-in, the token is 32 random bytes, and it can be
 * revoked - which is the only way to un-share a URL.
 *
 * Outside `/backend`, so the firewall's `PUBLIC_ACCESS` fallback applies rather
 * than a hole punched in the admin rules. A route under `/backend` would have
 * needed an `access_control` exception, and exceptions there are read by everyone
 * who ever audits this file.
 *
 * Answers 404 and never 403: a wrong token must not reveal that a right one
 * exists.
 */
#[Route('/planning/feed', name: 'planning_feed')]
final class PlanningFeedController extends AbstractController
{
    public function __construct(
        private readonly PlanningRepository $plannings,
        private readonly IcalWriter $writer,
        private readonly PlanningContext $planningContext,
    ) {}

    /**
     * The token is constrained to the alphabet it is generated from, so a path
     * carrying anything else never reaches a query.
     */
    #[Route(
        '/{token}.ics',
        name: '_show',
        requirements: ['token' => '[A-Za-z0-9_-]{20,64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $token): Response
    {
        // A module switched off stops serving. Checked here rather than left to a
        // route gate, because this route is not under `/backend` and the gates
        // there do not see it.
        if (!$this->planningContext->isBackendEnabled()) {
            throw $this->createNotFoundException();
        }

        $planning = $this->plannings->findOneBy(['feedToken' => $token]);

        if (!$planning instanceof PlanningInterface) {
            throw $this->createNotFoundException();
        }

        $response = new Response($this->writer->write($planning));
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        // Named after the calendar, so a reader who downloads it rather than
        // subscribing gets a file they can identify.
        $response->headers->set(
            'Content-Disposition',
            sprintf('inline; filename="%s.ics"', preg_replace('/[^A-Za-z0-9_-]+/', '-', $planning->getName()) ?? 'calendar'),
        );
        // Not cacheable by anything in between: the URL is a secret, and a shared
        // cache holding this would serve it to whoever asks next.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
