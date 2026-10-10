<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Dto\SpaceAccessLinkInputFactoryInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceLinkMailer;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceReviewInviter;
use Aurora\Module\Studio\SpaceAccess\View\SpaceAccessViewBuilder;
use DateTimeImmutable;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_string;

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
        protected readonly SpaceLinkMailer $linkMailer,
    ) {}

    /**
     * Asks the client to go and review what is waiting for their opinion.
     *
     * Under `studio.spaces.share` and not `view`: the action issues addresses
     * and revokes some, so it belongs to the right to share a space and not to
     * the right to look at it.
     *
     * The response carries both numbers rather than a plain success: "sent"
     * does not say whether anyone received it. A space without a link able to
     * answer returns zero recipients, and the screen says so instead of
     * announcing a send that reached nobody.
     */
    #[Route('/review', name: '_review', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function review(CustomerSpace $space): JsonResponse
    {
        return $this->jsonSuccess($this->reviewInviter->invite($space));
    }

    /**
     * Under `studio.spaces.share`, like the rest of the page: it lists the
     * email addresses the space is open to, and the right is called "Voir et
     * donner les accès client". The plain `view` used to open it.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    #[IsGranted('studio.spaces.share')]
    public function index(CustomerSpace $space): Response
    {
        return $this->render('@Studio/suite/space-content/access.html.twig', $this->viewBuilder->accessView($space));
    }

    /**
     * Opens the client's page, as this link renders it.
     *
     * **A real temporary link, and not a made-up page.** The clear-text token
     * only exists at creation; rebuilding the page with an invented token gives
     * a screen that displays and where nothing answers, so no Drive folder and
     * no files - that is, everything one came to check. The preview therefore
     * issues a link for real, valid for a few minutes, invisible in the list,
     * and unable to write whatever it allows.
     *
     * A redirect rather than a render: the client's page is served by its own
     * route, with its chat cookie and its headers. Rendering it a second time
     * here would be a second way of producing it, and the two would end up no
     * longer saying the same thing.
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

    /**
     * Mints an address, and hands it back exactly once.
     *
     * The response is the only place the secret ever exists in readable form.
     * Nothing stores it, so nothing can show it again: the screen has to make
     * the reader copy it now, and says so.
     */
    #[Route('/issue', name: '_issue', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function issue(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $input = $this->inputFactory->fromArray($payload);

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
            $input->canSeeContracts(),
        );

        // Written by the application when asked, which the screen does by
        // default: the address is still readable here, and only here.
        $invited = true === ($payload['sendInvitation'] ?? false) && $this->linkMailer->invite($link);

        return $this->jsonSuccess([...$this->viewBuilder->issuedPayload($space, $link), 'invited' => $invited]);
    }

    /**
     * Writes the link's address to its recipient again.
     *
     * Nothing is revoked: the mail carries the address the application can
     * rebuild, which opens the same page as the one they may have bookmarked.
     */
    #[Route('/{linkId}/invite', name: '_invite', requirements: ['linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function invite(
        CustomerSpace $space,
        #[MapEntity(id: 'linkId')]
        SpaceAccessLink $link,
    ): JsonResponse {
        $this->assertOwned($space, $link);

        if (!$this->linkMailer->invite($link)) {
            return $this->jsonFailure('suite.studio.space_access.errors.invite_unusable', HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess([...$this->viewBuilder->listPayload($space), 'invited' => true]);
    }

    /**
     * Gives the link a short address, or a new one (10/10/2026). The name is
     * the readable part; a random end is added to it, so that knowing the
     * client's name is not enough to open their space.
     */
    #[Route('/{linkId}/alias', name: '_alias', requirements: ['linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function alias(
        CustomerSpace $space,
        #[MapEntity(id: 'linkId')]
        SpaceAccessLink $link,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $link);
        if (!$link->isUsable(new DateTimeImmutable())) {
            return $this->jsonFailure('suite.studio.space_access.errors.alias_unusable', HttpStatusEnum::Conflict->value);
        }

        $name = $this->decodeJson($request)['name'] ?? '';
        $this->links->giveAlias($link, is_string($name) ? $name : '');

        return $this->jsonSuccess($this->viewBuilder->listPayload($space));
    }

    #[Route('/{linkId}/alias/remove', name: '_alias_remove', requirements: ['linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.share')]
    public function removeAlias(
        CustomerSpace $space,
        #[MapEntity(id: 'linkId')]
        SpaceAccessLink $link,
    ): JsonResponse {
        $this->assertOwned($space, $link);

        $this->links->removeAlias($link);

        return $this->jsonSuccess($this->viewBuilder->listPayload($space));
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
