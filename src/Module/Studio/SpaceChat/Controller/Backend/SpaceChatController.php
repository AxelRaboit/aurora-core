<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatMessageManagerInterface;
use Aurora\Module\Studio\SpaceChat\View\SpaceChatViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The studio's side of a space's conversation.
 *
 * Alongside the board's controller rather than inside it, on the same
 * `/workspace/{id}` root: it is the same screen to a reader, and a different
 * subject to the code. The board's controller is about cards, columns and the
 * files on them; none of its routes would read any better for having a chat
 * bolted to them.
 *
 * **Every route names the space and every handler checks what it was handed
 * belongs to it.** The message arrives as its own entity through the URL, so
 * nothing stops a crafted request from naming one client's message under
 * another client's space - `assertOwned` is what does.
 *
 * Reading is `view` and writing is `edit`, which is how the rest of the module
 * splits it: somebody who may look at a space can follow the conversation, and
 * writing into a customer's space is an act of the agency.
 */
#[Route('/workspace/{id}/chat', name: 'workspace_space_chat', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceChatController extends AbstractController
{
    use SpaceOwnershipTrait;
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceChatMessageManagerInterface $messages,
        protected readonly SpaceChatViewBuilder $viewBuilder,
    ) {}

    /**
     * The conversation as it stands.
     *
     * **This is what makes the hub optional.** A page with no live connection -
     * because no hub is configured, or because the browser dropped the one it
     * had - asks here instead, and is right again. Without it, "no hub" would
     * mean "reload the page to see an answer", which is not a chat.
     */
    #[Route('/messages', name: '_messages', methods: [HttpMethodEnum::Get->value])]
    public function messages(CustomerSpace $space): JsonResponse
    {
        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    #[Route('', name: '_post', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function post(CustomerSpace $space, Request $request): JsonResponse
    {
        $body = Str::trimFromArray($this->decodeJson($request), 'body');

        if ('' === $body) {
            return $this->jsonInvalidInput(['body' => 'backend.studio.space_chat.errors.body_required']);
        }

        try {
            $this->messages->postAsStudio($space, $body);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * Removes one of the studio's own messages.
     *
     * A client's message is refused by the Manager, for the reason the card
     * threads give: a provider who can delete a customer's complaint has a
     * record of the engagement that proves nothing.
     */
    #[Route('/{messageId}/delete', name: '_delete', requirements: ['messageId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function delete(
        CustomerSpace $space,
        #[MapEntity(id: 'messageId')]
        SpaceChatMessage $message,
    ): JsonResponse {
        $this->assertOwned($space, $message->getSpace()->getId());

        try {
            $this->messages->delete($message);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }
}
