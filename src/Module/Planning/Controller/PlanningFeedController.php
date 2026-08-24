<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Planning\Feed\IcalWriter;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Link\Manager\PlanningShareLinkManagerInterface;
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
 * The token now lives in `core_planning_share_links` rather than a column on the
 * calendar, so one address can serve several calendars and can be given an expiry.
 * A feed's expiry is normally null on purpose: a subscription that closes
 * underneath a phone does not report an error, it just goes quiet.
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
        private readonly PlanningShareLinkManagerInterface $shareLinks,
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

        // One call for unknown, expired, revoked and wrong-mode alike. The `Ics`
        // mode is part of the question: a token minted for a guest's web page must
        // not also answer as a permanent subscription.
        $link = $this->shareLinks->resolveUsable($token, PlanningShareLinkModeEnum::Ics);

        if (!$link instanceof PlanningShareLinkInterface) {
            throw $this->createNotFoundException();
        }

        $calendars = $link->getCalendars()->toArray();

        if ([] === $calendars) {
            throw $this->createNotFoundException();
        }

        // The link's own label names the feed, not the first calendar's name: a
        // link can carry several, and a subscription called "Pro" that also holds
        // the personal calendar is a lie the reader cannot see.
        $response = new Response($this->writer->writeMany(
            array_values($calendars),
            $link->getLabel(),
            $calendars[array_key_first($calendars)]->getTimezone(),
        ));
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        // Named after the calendar, so a reader who downloads it rather than
        // subscribing gets a file they can identify.
        $response->headers->set(
            'Content-Disposition',
            sprintf('inline; filename="%s.ics"', preg_replace('/[^A-Za-z0-9_-]+/', '-', $link->getLabel()) ?? 'calendar'),
        );
        // Not cacheable by anything in between: the URL is a secret, and a shared
        // cache holding this would serve it to whoever asks next.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
