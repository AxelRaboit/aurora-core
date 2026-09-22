<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Dto\SpaceAccessLinkInputFactoryInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceReviewInviter;
use Aurora\Module\Studio\SpaceAccess\View\SpaceAccessViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Who can open a space from outside, managed from inside it.
 *
 * A third tab of the space's own shell rather than a screen in the back-office
 * menu: issuing an address is something somebody does while looking at the plan
 * they are about to show, and a list of every link of every client answers a
 * question nobody asks.
 */
#[Route('/workspace/{id}/access', name: 'workspace_space_access', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceAccessController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceAccessLinkManagerInterface $links,
        protected readonly SpaceAccessLinkInputFactoryInterface $inputFactory,
        protected readonly SpaceAccessViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly SpaceReviewInviter $reviewInviter,
    ) {}

    /**
     * Demande au client d'aller relire ce qui attend son avis.
     *
     * Sous `studio.spaces.share` et pas `view` : l'action émet des adresses et
     * en révoque, donc elle relève du droit de partager un espace et pas de
     * celui de le regarder.
     *
     * La réponse porte les deux nombres plutôt qu'un simple succès : « envoyé »
     * ne dit pas si quelqu'un l'a reçu. Un espace sans lien capable de répondre
     * renvoie zéro destinataire, et l'écran le dit au lieu d'annoncer un envoi
     * qui n'a eu lieu pour personne.
     */
    #[Route('/review', name: '_review', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function review(CustomerSpace $space): JsonResponse
    {
        return $this->jsonSuccess($this->reviewInviter->invite($space));
    }

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(CustomerSpace $space): Response
    {
        return $this->render('@Studio/backend/space-content/access.html.twig', $this->viewBuilder->accessView($space));
    }

    /**
     * Mints an address, and hands it back exactly once.
     *
     * The response is the only place the secret ever exists in readable form.
     * Nothing stores it, so nothing can show it again: the screen has to make
     * the reader copy it now, and says so.
     */
    /**
     * Ouvre la page du client, telle que ce lien la rend.
     *
     * **Un vrai lien temporaire, et non une page fabriquée.** Le jeton en clair
     * n'existe qu'à la création ; reconstruire la page avec un jeton inventé
     * donne un écran qui s'affiche et dont rien ne répond, donc ni dossier
     * Drive ni fichiers - c'est-à-dire tout ce qu'on venait vérifier. L'aperçu
     * émet donc un lien pour de bon, valable quelques minutes, invisible dans
     * la liste, et incapable d'écrire quoi qu'il autorise.
     *
     * Une redirection plutôt qu'un rendu : la page du client est servie par sa
     * propre route, avec son cookie de discussion et ses en-têtes. La rendre
     * une seconde fois ici serait une seconde façon de la produire, et les
     * deux finiraient par ne plus dire la même chose.
     */
    #[Route('/{linkId}/preview', name: '_preview', requirements: ['linkId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    #[IsGranted('studio.spaces.share')]
    public function preview(
        CustomerSpace $space,
        #[MapEntity(id: 'linkId')]
        SpaceAccessLink $link,
    ): Response {
        $this->assertOwned($space, $link);

        $preview = $this->links->preview($link);

        return $this->redirectToRoute('public_space_show', [
            'selector' => $preview->getSelector(),
            'token' => $preview->getPlainToken(),
        ]);
    }

    #[Route('/issue', name: '_issue', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function issue(CustomerSpace $space, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $link = $this->links->issue(
            $space,
            $input->getRecipientEmail(),
            $input->getLabel(),
            $input->getValidForDays(),
            $input->canApprove(),
            $input->canComment(),
            $input->canChat(),
            $input->canUpload(),
            $input->canSeeDrive(),
        );

        return $this->jsonSuccess($this->viewBuilder->issuedPayload($space, $link));
    }

    #[Route('/{linkId}/revoke', name: '_revoke', requirements: ['linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function revoke(
        CustomerSpace $space,
        #[MapEntity(id: 'linkId')]
        SpaceAccessLink $link,
    ): JsonResponse {
        $this->assertOwned($space, $link);

        $this->links->revoke($link);

        return $this->jsonSuccess($this->viewBuilder->listPayload($space));
    }

    #[Route('/{linkId}/delete', name: '_delete', requirements: ['linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function delete(
        CustomerSpace $space,
        #[MapEntity(id: 'linkId')]
        SpaceAccessLink $link,
    ): JsonResponse {
        $this->assertOwned($space, $link);

        $this->links->delete($link);

        return $this->jsonSuccess($this->viewBuilder->listPayload($space));
    }

    /**
     * A 404 and not a 403: one space must never reach into another's, and
     * saying "that link exists but is not yours" says more than refusing to
     * answer does.
     */
    private function assertOwned(CustomerSpace $space, SpaceAccessLink $link): void
    {
        if ($link->getSpace()->getId() !== $space->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
