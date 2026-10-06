<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\Sharing\ShareToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The addresses of shared presentations, from before they moved to
 * deliverables: `/decks/{jeton}` redirects for good to
 * `/deliverables/{jeton}`.
 *
 * The migration copied each share link into a reading link with the same
 * token: the address already sent to a client keeps leading to the same
 * document. Nothing is looked up here: the reading page judges the token,
 * with its rules (expired, revoked, password, module off), and an unknown
 * token answers the same 404 as before there.
 *
 * Named `public_deliverable_from_deck`: they switch off with Studio, like
 * every reading page, see `StudioRouteGateSubscriber`.
 */
#[Route('/decks', name: 'public_deliverable_from_deck')]
final class LegacyDeckLinkController extends AbstractController
{
    use PrivateAddressResponseTrait;

    #[Route('/{token}', name: '', requirements: ['token' => ShareToken::PATTERN], methods: [HttpMethodEnum::Get->value])]
    public function show(string $token): RedirectResponse
    {
        return $this->toReading($token);
    }

    /**
     * The password form of a page left open from before the update:
     * redirected too, and the reading page asks for the password again.
     */
    #[Route('/{token}/unlock', name: '_unlock', requirements: ['token' => ShareToken::PATTERN], methods: [HttpMethodEnum::Post->value])]
    public function unlock(string $token): RedirectResponse
    {
        return $this->toReading($token);
    }

    private function toReading(string $token): RedirectResponse
    {
        $response = $this->redirectToRoute('public_deliverable_read', ['token' => $token], RedirectResponse::HTTP_MOVED_PERMANENTLY);
        $this->privately($response);

        return $response;
    }
}
