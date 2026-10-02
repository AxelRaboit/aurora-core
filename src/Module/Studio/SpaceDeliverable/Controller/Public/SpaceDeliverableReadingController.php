<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverableLinkInterface;
use Aurora\Module\Studio\SpaceDeliverable\Repository\SpaceDeliverableLinkRepository;
use Aurora\Module\Studio\SpaceDeliverable\Service\DeliverablePageRenderer;
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

/**
 * Un livrable ouvert par un lien de lecture, sans l'espace du client autour.
 *
 * Une seule réponse pour « pas de tel lien », « révoqué » et « expiré » : dire
 * au porteur laquelle confirmerait que l'adresse était réelle. Un mot de passe
 * faux répond ce que répond une adresse fausse.
 *
 * Nommées `public_space…` : elles s'éteignent avec les espaces clients, par la
 * garde des routes du Studio.
 */
#[Route('/deliverables', name: 'public_space_deliverable_read')]
final class SpaceDeliverableReadingController extends AbstractController
{
    use PrivateAddressResponseTrait;

    /** Les liens que ce navigateur a déjà ouverts, dans sa session. */
    private const string UNLOCKED = 'studio.space_deliverable.unlocked';

    public function __construct(
        private readonly SpaceDeliverableLinkRepository $links,
        private readonly DeliverablePageRenderer $renderer,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactoryInterface $spaceDeliverablePasswordLimiter,
    ) {}

    #[Route('/{token}', name: '', requirements: ['token' => '[a-f0-9]{64}'], methods: [HttpMethodEnum::Get->value])]
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

        $link->touch(new DateTimeImmutable());
        $this->entityManager->flush();

        return $this->privately($this->renderer->render($deliverable));
    }

    #[Route('/{token}/unlock', name: '_unlock', requirements: ['token' => '[a-f0-9]{64}'], methods: [HttpMethodEnum::Post->value])]
    public function unlock(string $token, Request $request): Response
    {
        if (false === $this->spaceDeliverablePasswordLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $link = $this->links->findByToken($token);
        $password = (string) $request->request->get('password', '');

        if (!$link instanceof SpaceDeliverableLinkInterface
            || !$link->isUsable(new DateTimeImmutable())
            || !$link->isLocked()
            || !password_verify($password, (string) $link->getPasswordHash())
        ) {
            return $this->privately($this->render('@Studio/public/deliverable_locked.html.twig', [
                'token' => $token,
                'failed' => true,
            ]));
        }

        $unlocked = $request->getSession()->get(self::UNLOCKED, []);
        $unlocked = is_array($unlocked) ? $unlocked : [];
        $unlocked[$token] = true;
        $request->getSession()->set(self::UNLOCKED, $unlocked);

        return $this->privately($this->redirectToRoute('public_space_deliverable_read', ['token' => $token]));
    }

    private function readable(string $token): SpaceDeliverableLinkInterface
    {
        $link = $this->links->findByToken($token);

        if (!$link instanceof SpaceDeliverableLinkInterface || !$link->isUsable(new DateTimeImmutable())) {
            throw $this->createNotFoundException();
        }

        return $link;
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
