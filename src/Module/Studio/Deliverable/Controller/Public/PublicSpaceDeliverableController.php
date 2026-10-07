<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\View\DeliverableSlidesViewBuilder;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A deliverable read by the client, from their space.
 *
 * The space address authorises reading, as for the rest of the client's
 * page: no second link, no password. The deliverable must belong to the
 * space the link opens, and be open to the client; a closed deliverable
 * answers the same 404 as a deliverable that does not exist.
 *
 * A page reads through the page template, a presentation through the slide
 * reader, never with the speaker notes.
 */
#[Route('/spaces', name: 'public_space')]
final class PublicSpaceDeliverableController extends AbstractController
{
    use PrivateAddressResponseTrait;

    public function __construct(
        private readonly SpaceAccessLinkManagerInterface $links,
        private readonly DeliverableRepository $deliverables,
        private readonly DeliverablePageRenderer $renderer,
        private readonly DeliverableSlidesViewBuilder $slidesView,
    ) {}

    #[Route(
        '/{selector}/{token}/deliverables/{deliverableId}',
        name: '_deliverable',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'deliverableId' => '\d+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $selector, string $token, int $deliverableId, Request $request): Response
    {
        $link = $this->links->resolveUsable($selector, $token);

        // Looked up in the space the link opens, not everywhere and then
        // compared: another space's deliverable is never read for this link.
        $deliverable = $link instanceof SpaceAccessLinkInterface
            ? $this->deliverables->findInSpace($link->getSpace(), $deliverableId)
            : null;

        if (!$link instanceof SpaceAccessLinkInterface || !$deliverable instanceof DeliverableInterface || !$deliverable->isVisibleToClient()) {
            throw $this->createNotFoundException();
        }

        $request->setLocale($deliverable->getLocale());
        $spaceUrl = $this->generateUrl('public_space_show', ['selector' => $selector, 'token' => $token]);

        // A presentation reads slide by slide, as through a reading link: never
        // with the speaker notes, stripped before the template. Opening it
        // counts as opening the space.
        if ($deliverable->isSlides()) {
            $this->links->markOpened($link);

            return $this->privately($this->render('@Studio/public/deliverable_slides.html.twig', [
                'deck' => $this->slidesView->readerDeck($deliverable),
                'expiresAt' => null,
                'backUrl' => $spaceUrl,
            ]));
        }

        // Reading a deliverable is reading the space: the link records the
        // opening, as the space page does. Switching views does not count.
        $view = DeliverablePageRenderer::requestedView($request->query->all()['view'] ?? null);
        if (null === $view) {
            $this->links->markOpened($link);
        }

        return $this->privately($this->renderer->renderForReader(
            $deliverable,
            $request->query->getBoolean('print'),
            $spaceUrl,
            $view,
        ));
    }
}
