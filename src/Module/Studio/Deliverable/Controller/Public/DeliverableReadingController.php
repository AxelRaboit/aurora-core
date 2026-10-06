<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLinkInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverableLinkIssuer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\View\DeliverableSlidesViewBuilder;
use Aurora\Module\Studio\Sharing\ShareToken;
use Aurora\Module\Studio\StudioContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function password_verify;
use function sprintf;

/**
 * Un livrable ouvert par un lien de lecture, sans l'espace du client autour.
 *
 * Une seule réponse pour « pas de tel lien », « révoqué » et « expiré » : dire
 * au porteur laquelle confirmerait que l'adresse était réelle. Un mot de passe
 * faux répond ce que répond une adresse fausse.
 *
 * Nommées `public_deliverable_read` : elles s'éteignent avec le module
 * Livrables pour un livrable de Studio, avec les espaces clients pour celui
 * d'un espace, par la garde de {@see self::isServed()}.
 */
#[Route('/deliverables', name: 'public_deliverable_read')]
final class DeliverableReadingController extends AbstractController
{
    use PrivateAddressResponseTrait;

    /** Les liens que ce navigateur a déjà ouverts, dans sa session. */
    private const string UNLOCKED = 'studio.deliverable.unlocked';

    public function __construct(
        private readonly DeliverableLinkRepository $links,
        private readonly DeliverablePageRenderer $renderer,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactoryInterface $deliverablePasswordLimiter,
        private readonly StudioContext $studioContext,
        private readonly DeliverableSlidesViewBuilder $slidesView,
    ) {}

    #[Route('/{token}', name: '', requirements: ['token' => ShareToken::PATTERN], methods: [HttpMethodEnum::Get->value])]
    public function show(string $token, Request $request): Response
    {
        $link = $this->readable($token);

        if ($link->isLocked() && !$this->isUnlocked($request, $token)) {
            return $this->privately($this->render('@Studio/public/deliverable_locked.html.twig', [
                'token' => $token,
                'failed' => false,
            ]));
        }

        $deliverable = $link->getDeliverable();
        $request->setLocale($deliverable->getLocale());

        // Un diaporama se lit diapositive par diapositive, comme une
        // présentation partagée : jamais par le gabarit des pages, et jamais
        // avec les notes de l'orateur, retirées avant le gabarit.
        if ($deliverable->isSlides()) {
            $link->touch(new DateTimeImmutable());
            $this->entityManager->flush();

            return $this->privately($this->render('@Studio/public/deliverable_slides.html.twig', [
                'deck' => $this->slidesView->readerDeck($deliverable),
                'expiresAt' => $link->getExpiresAt(),
            ]));
        }

        // Changer de vue (présentation, page) n'est pas ouvrir de nouveau le
        // lien : le compteur de l'auteur dit combien de fois on est venu, pas
        // combien de fois on a basculé.
        $view = DeliverablePageRenderer::requestedView($request->query->all()['view'] ?? null);
        if (null === $view) {
            $link->touch(new DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->privately($this->renderer->renderForReader($deliverable, $request->query->getBoolean('print'), view: $view));
    }

    #[Route('/{token}/unlock', name: '_unlock', requirements: ['token' => ShareToken::PATTERN], methods: [HttpMethodEnum::Post->value])]
    public function unlock(string $token, Request $request): Response
    {
        // Par adresse de lecture et par IP, et seuls les échecs comptent : un
        // bureau derrière une seule adresse ne se bloque pas pour tous ses
        // liens, ni parce que dix collègues ont ouvert le même document. La
        // lecture sans jeton (`consume(0)`) dit s'il reste de la place ; le
        // jeton n'est dépensé qu'à l'échec, plus bas.
        $limiter = $this->deliverablePasswordLimiter->create(sprintf('%s|%s', $request->getClientIp(), $token));
        // `consume(0)` accepte toujours, même fenêtre pleine : c'est ce qui
        // reste de jetons qui dit si l'on est bloqué.
        if ($limiter->consume(0)->getRemainingTokens() < 1) {
            throw new TooManyRequestsHttpException();
        }

        $link = $this->links->findByToken($token);
        // Nettoyé comme à la création : un mot de passe tapé avec une espace
        // de fin ouvre le lien qu'il a fermé.
        $password = DeliverableLinkIssuer::password(['password' => (string) $request->request->get('password', '')]);

        if (!$link instanceof DeliverableLinkInterface
            || !$link->isUsable(new DateTimeImmutable())
            || !$this->isServed($link)
            || $link->getDeliverable()->isTrashed()
            || !$link->isLocked()
            || !password_verify($password, (string) $link->getPasswordHash())
        ) {
            $limiter->consume();

            return $this->privately($this->render('@Studio/public/deliverable_locked.html.twig', [
                'token' => $token,
                'failed' => true,
            ]));
        }

        $unlocked = $request->getSession()->get(self::UNLOCKED, []);
        $unlocked = is_array($unlocked) ? $unlocked : [];
        $unlocked[$token] = true;
        $request->getSession()->set(self::UNLOCKED, $unlocked);

        return $this->privately($this->redirectToRoute('public_deliverable_read', ['token' => $token]));
    }

    private function readable(string $token): DeliverableLinkInterface
    {
        $link = $this->links->findByToken($token);

        if (!$link instanceof DeliverableLinkInterface || !$link->isUsable(new DateTimeImmutable()) || !$this->isServed($link) || $link->getDeliverable()->isTrashed()) {
            throw $this->createNotFoundException();
        }

        return $link;
    }

    /**
     * La partie de Studio dont le livrable dépend est-elle allumée ?
     *
     * Un livrable d'espace s'éteint avec les espaces, un livrable de Studio
     * avec le module Livrables : le même 404 qu'un lien inconnu, plutôt
     * qu'une page servie par une partie que l'administrateur a coupée.
     */
    private function isServed(DeliverableLinkInterface $link): bool
    {
        return $link->getDeliverable()->isStandalone()
            ? $this->studioContext->areDeliverablesEnabled()
            : $this->studioContext->areSpacesEnabled();
    }

    private function isUnlocked(Request $request, string $token): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $unlocked = $request->getSession()->get(self::UNLOCKED, []);

        return is_array($unlocked) && true === ($unlocked[$token] ?? false);
    }
}
