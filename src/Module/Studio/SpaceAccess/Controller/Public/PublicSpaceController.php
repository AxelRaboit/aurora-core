<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\View\PublicSpaceViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A client's own view of their space, opened by a secret address.
 *
 * **Read-only, and that is a scope decision rather than a stage.** The rights
 * to comment and to approve arrive with the columns that carry them and the
 * rate limit that a guest write needs - the two things the calendar's share
 * links are documented as deliberately missing before write access is opened.
 * Shipping the reading half first means the client gets the thing they ask for
 * every week without anybody rushing the half that has an attacker.
 *
 * One page for every refusal: unknown selector, wrong secret, revoked, expired.
 * Telling a stranger which of those it was tells them which guesses landed.
 */
#[Route('/spaces', name: 'public_space')]
final class PublicSpaceController extends AbstractController
{
    public function __construct(
        private readonly SpaceAccessLinkManagerInterface $links,
        private readonly PublicSpaceViewBuilder $viewBuilder,
    ) {}

    /**
     * The alphabets are constrained in the route, so a path carrying anything
     * else never reaches a query.
     */
    #[Route(
        '/{selector}/{token}',
        name: '_show',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $selector, string $token): Response
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface) {
            return $this->privately($this->render('@Studio/public/unavailable.html.twig', [
                // The page the contracts already use, with the one sentence
                // that has to name what the reader was expecting.
                'messageKey' => 'studio.public.space.unavailable_message',
            ]));
        }

        $this->links->markOpened($link);

        return $this->privately($this->render('@Studio/public/space.html.twig', $this->viewBuilder->view($link)));
    }

    /**
     * Keeps the page out of every cache between here and the reader.
     *
     * The address is a secret handed to one person; a shared proxy holding the
     * answer would hand it to the next person asking for the same URL, and a
     * browser cache would leave a client's content plan on a machine after the
     * link is revoked.
     */
    private function privately(Response $response): Response
    {
        // The same three headers the contract page sets, and set the same way:
        // two pages that keep a secret address out of caches should not differ
        // in how thoroughly they do it.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }
}
