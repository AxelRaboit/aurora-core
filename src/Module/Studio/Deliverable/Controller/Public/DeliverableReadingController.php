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
 * A deliverable opened through a reading link, without the client's space
 * around it.
 *
 * A single answer for "no such link", "revoked" and "expired": telling the
 * holder which one would confirm the address was real. A wrong password
 * answers what a wrong address answers.
 *
 * Named `public_deliverable_read`: they switch off with the Deliverables
 * module for a Studio deliverable, with client spaces for a space's, through
 * the guard in {@see self::isServed()}.
 */
#[Route('/deliverables', name: 'public_deliverable_read')]
final class DeliverableReadingController extends AbstractController
{
    use PrivateAddressResponseTrait;

    /** The links this browser has already opened, in its session. */
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

        // A slideshow is read slide by slide, like a shared presentation:
        // never through the page template, and never with the speaker notes,
        // removed before the template.
        if ($deliverable->isSlides()) {
            $link->touch(new DateTimeImmutable());
            $this->entityManager->flush();

            return $this->privately($this->render('@Studio/public/deliverable_slides.html.twig', [
                'deck' => $this->slidesView->readerDeck($deliverable),
                'expiresAt' => $link->getExpiresAt(),
            ]));
        }

        // Switching views (presentation, page) is not opening the link again:
        // the author's counter says how many times someone came, not how many
        // times they switched.
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
        // Per reading address and per IP, and only failures count: an office
        // behind a single address does not get blocked for all its links, nor
        // because ten colleagues opened the same document. The token-free read
        // (`consume(0)`) says whether there is room left; the token is only
        // spent on failure, further down.
        $limiter = $this->deliverablePasswordLimiter->create(sprintf('%s|%s', $request->getClientIp(), $token));
        // `consume(0)` always accepts, even with a full window: the tokens
        // left are what says whether you are blocked.
        if ($limiter->consume(0)->getRemainingTokens() < 1) {
            throw new TooManyRequestsHttpException();
        }

        $link = $this->links->findByToken($token);
        // Cleaned as on creation: a password typed with a trailing space
        // opens the link it locked.
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
     * Is the part of Studio the deliverable depends on switched on?
     *
     * A space deliverable switches off with spaces, or with its space moved to
     * the trash, a Studio deliverable with the Deliverables module: the same
     * 404 as an unknown link, rather than a page served by a part the
     * administrator turned off.
     */
    private function isServed(DeliverableLinkInterface $link): bool
    {
        $deliverable = $link->getDeliverable();

        if ($deliverable->isStandalone()) {
            return $this->studioContext->areDeliverablesEnabled();
        }

        // And a trashed space takes the reading of its deliverables with it.
        return $this->studioContext->areSpacesEnabled() && true !== $deliverable->getSpace()?->isTrashed();
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
