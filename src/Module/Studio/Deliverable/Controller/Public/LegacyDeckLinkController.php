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
 * Les adresses des présentations partagées, d'avant leur passage aux
 * livrables : `/decks/{jeton}` renvoie pour de bon vers `/deliverables/{jeton}`.
 *
 * La migration a recopié chaque lien de partage en lien de lecture avec le
 * même jeton : l'adresse déjà envoyée à un client continue de mener au même
 * document. Rien n'est cherché ici : c'est la page de lecture qui juge le
 * jeton, avec ses règles (expiré, révoqué, mot de passe, module éteint), et
 * un jeton inconnu y répond le même 404 qu'avant.
 *
 * Nommées `public_deliverable_from_deck` : elles s'éteignent avec Studio,
 * comme toute page de lecture, cf. `StudioRouteGateSubscriber`.
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
     * Le formulaire du mot de passe d'une page restée ouverte avant la mise à
     * jour : renvoyé lui aussi, la page de lecture redemande le mot de passe.
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
