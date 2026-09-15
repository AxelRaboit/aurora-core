<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentColumnInputFactoryInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInputFactoryInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentColumnManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentCommentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\View\SpaceBoardViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_map;
use function array_values;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * The board of one space.
 *
 * **Outside `/backend`, and still behind the admin firewall.** The address says
 * what the screen is - a place you work in, not a page of the back-office - and
 * `security.yaml` carries `workspace` in the admin pattern so the session is
 * restored all the same. The two questions are separate and are answered
 * separately: what is drawn, and who is recognised.
 *
 * **Every route names the space and every handler checks that what it was
 * handed belongs to it.** The card and the column arrive as their own entities
 * through the URL, so nothing stops a crafted request from naming one client's
 * card under another client's space - `assertOwned` is what does, and it is the
 * single thing standing between two clients' boards.
 */
#[Route('/workspace/{id}', name: 'workspace_space_content', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceContentController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceContentItemManagerInterface $itemManager,
        protected readonly SpaceContentColumnManagerInterface $columnManager,
        protected readonly SpaceContentCommentManagerInterface $comments,
        protected readonly SpaceContentItemInputFactoryInterface $itemInputFactory,
        protected readonly SpaceContentColumnInputFactoryInterface $columnInputFactory,
        protected readonly SpaceBoardViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
    ) {}

    /**
     * The space's content, in whichever view the reader prefers.
     *
     * **One address for three views**, because they show the same rows and
     * differ only in how somebody likes to read them. The board, the list and
     * the month were two routes and a tab before; a reader then had to know
     * that one alternative lived in a tab and another in a selector, for the
     * same content. What is on screen belongs in the URL, and how it is drawn
     * belongs to the person drawing it.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function content(CustomerSpace $space): Response
    {
        return $this->render('@Studio/backend/space-content/content.html.twig', $this->viewBuilder->contentView($space));
    }

    /**
     * The calendar's only write: a card moved to another day.
     *
     * Its own route rather than `update`, because dragging a card across a
     * month should not have to resend a title and a step to say "this goes out
     * on Thursday instead".
     */
    #[Route('/content/{itemId}/schedule', name: '_item_schedule', requirements: ['itemId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function scheduleItem(
        CustomerSpace $space,
        #[MapEntity(id: 'itemId')]
        SpaceContentItem $item,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $item->getSpace()->getId());

        $payload = $this->decodeJson($request);
        $scheduledAt = $payload['scheduledAt'] ?? null;

        $this->itemManager->reschedule($item, is_string($scheduledAt) && '' !== $scheduledAt ? $scheduledAt : null);

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/content/create', name: '_item_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function createItem(CustomerSpace $space, Request $request): JsonResponse
    {
        $input = $this->itemInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->itemManager->create($space, $input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/content/{itemId}/update', name: '_item_update', requirements: ['itemId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function updateItem(
        CustomerSpace $space,
        #[MapEntity(id: 'itemId')]
        SpaceContentItem $item,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $item->getSpace()->getId());

        $input = $this->itemInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->itemManager->update($item, $input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/content/{itemId}/delete', name: '_item_delete', requirements: ['itemId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function deleteItem(
        CustomerSpace $space,
        #[MapEntity(id: 'itemId')]
        SpaceContentItem $item,
    ): JsonResponse {
        $this->assertOwned($space, $item->getSpace()->getId());

        $this->itemManager->delete($item);

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/content/reorder', name: '_item_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function reorderItems(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        // A missing or foreign column is left to the Manager: it holds the
        // translator and already words that refusal for every other route.
        $columnId = is_numeric($payload['columnId'] ?? null) ? (int) $payload['columnId'] : 0;

        try {
            $this->itemManager->reorder($space, $columnId, $this->ids($payload['itemIds'] ?? null));
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    /**
     * A message on a card's thread, signed by whoever is logged in.
     *
     * **What is written here is read by the client.** One shared thread is what
     * makes it a conversation rather than two mailboxes, and the screen says so
     * where somebody types: a note meant for a colleague, written in this box,
     * is a note the customer reads.
     */
    #[Route('/content/{itemId}/comments', name: '_comment_post', requirements: ['itemId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function postComment(
        CustomerSpace $space,
        #[MapEntity(id: 'itemId')]
        SpaceContentItem $item,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $item->getSpace()->getId());

        $body = Str::trimFromArray($this->decodeJson($request), 'body');

        if ('' === $body) {
            return $this->jsonInvalidInput(['body' => 'backend.studio.space_content.errors.comment_required']);
        }

        try {
            $this->comments->postAsStudio($item, $body);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    /**
     * Removes one of the studio's own messages.
     *
     * A client's message is refused by the Manager: what a customer wrote is
     * what the studio was asked to act on, and a provider who can delete a
     * complaint has a record of the engagement that proves nothing.
     */
    #[Route('/comments/{commentId}/delete', name: '_comment_delete', requirements: ['commentId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function deleteComment(
        CustomerSpace $space,
        #[MapEntity(id: 'commentId')]
        SpaceContentComment $comment,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $comment->getItem()->getSpace()->getId());

        try {
            $this->comments->delete($comment);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/columns/create', name: '_column_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function createColumn(CustomerSpace $space, Request $request): JsonResponse
    {
        $input = $this->columnInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->columnManager->create($space, $input);

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/columns/{columnId}/update', name: '_column_update', requirements: ['columnId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function updateColumn(
        CustomerSpace $space,
        #[MapEntity(id: 'columnId')]
        SpaceContentColumn $column,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $column->getSpace()->getId());

        $input = $this->columnInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->columnManager->update($column, $input);

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/columns/{columnId}/delete', name: '_column_delete', requirements: ['columnId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function deleteColumn(
        CustomerSpace $space,
        #[MapEntity(id: 'columnId')]
        SpaceContentColumn $column,
    ): JsonResponse {
        $this->assertOwned($space, $column->getSpace()->getId());

        try {
            $this->columnManager->delete($column);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    #[Route('/columns/reorder', name: '_column_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function reorderColumns(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $this->columnManager->reorder($space, $this->ids($payload['columnIds'] ?? null));

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    /**
     * A 404 and not a 403, deliberately.
     *
     * Telling somebody that the card they asked for exists but is not theirs
     * says more than refusing to answer does. The screen cannot reach this
     * either way: it only ever sends ids it was given.
     */
    private function assertOwned(CustomerSpace $space, ?int $ownerId): void
    {
        if ($ownerId !== $space->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * The numeric ids of a payload list, and nothing else.
     *
     * @return list<int>
     */
    private function ids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($raw, is_numeric(...)),
        ));
    }
}
