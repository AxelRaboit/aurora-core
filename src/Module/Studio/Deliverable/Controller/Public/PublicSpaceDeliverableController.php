<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Un livrable lu par le client, depuis son espace.
 *
 * L'adresse de l'espace autorise la lecture, comme pour le reste de la page
 * du client : pas de second lien, pas de mot de passe. Le livrable doit
 * appartenir à l'espace que le lien ouvre, et être ouvert au client ; un
 * livrable fermé répond le même 404 qu'un livrable qui n'existe pas.
 */
#[Route('/spaces', name: 'public_space')]
final class PublicSpaceDeliverableController extends AbstractController
{
    use PrivateAddressResponseTrait;

    public function __construct(
        private readonly SpaceAccessLinkManagerInterface $links,
        private readonly DeliverableRepository $deliverables,
        private readonly DeliverablePageRenderer $renderer,
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
        $deliverable = $this->deliverables->find($deliverableId);

        if (!$link instanceof SpaceAccessLinkInterface
            || !$deliverable instanceof DeliverableInterface
            || $deliverable->getSpace()->getId() !== $link->getSpace()->getId()
            || !$deliverable->isVisibleToClient()
        ) {
            throw $this->createNotFoundException();
        }

        $request->setLocale($deliverable->getLocale());

        // Lire un livrable, c'est lire l'espace : le lien note son ouverture,
        // comme la page de l'espace le fait.
        $this->links->markOpened($link);

        return $this->privately($this->renderer->render(
            $deliverable,
            $this->generateUrl('public_space_show', ['selector' => $selector, 'token' => $token]),
        ));
    }
}
