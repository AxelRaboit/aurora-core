<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\View\SpaceInformationViewBuilder;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Module\Studio\SpaceChat\Service\SpaceChatHub;
use Aurora\Module\Studio\SpaceChat\View\SpaceChatViewBuilder;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentColumnInputFactoryInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInputFactoryInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentAttachmentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentColumnManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentCommentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Aurora\Module\Studio\SpaceContent\Service\SpaceOrphanedDocumentOffer;
use Aurora\Module\Studio\SpaceContent\View\SpaceBoardViewBuilder;
use Aurora\Module\Studio\SpaceFile\View\SpaceFilesViewBuilder;
use Aurora\Module\Studio\SpaceNote\View\SpaceNotesViewBuilder;
use Aurora\Module\Studio\SpaceResource\View\SpaceResourcesViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_filter;
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
    use SpaceOwnershipTrait;
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceContentItemManagerInterface $itemManager,
        protected readonly SpaceContentColumnManagerInterface $columnManager,
        protected readonly SpaceContentCommentManagerInterface $comments,
        protected readonly SpaceContentAttachmentManagerInterface $attachments,
        protected readonly DocumentRepository $documents,
        protected readonly SpaceContentItemInputFactoryInterface $itemInputFactory,
        protected readonly SpaceContentColumnInputFactoryInterface $columnInputFactory,
        protected readonly SpaceContentAttachmentRepository $attachmentRepository,
        protected readonly SpaceOrphanedDocumentOffer $orphanedOffer,
        protected readonly SpaceBoardViewBuilder $viewBuilder,
        protected readonly SpaceChatViewBuilder $chatViewBuilder,
        protected readonly SpaceChatChannelRepository $chatChannels,
        protected readonly SpaceChatHub $chatHub,
        protected readonly SpaceNotesViewBuilder $notesViewBuilder,
        protected readonly SpaceFilesViewBuilder $filesViewBuilder,
        protected readonly SpaceInformationViewBuilder $informationViewBuilder,
        protected readonly SpaceResourcesViewBuilder $resourcesViewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly StoredFileResponder $responder,
        protected readonly UploadPolicyProvider $uploadPolicies,
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
    public function content(CustomerSpace $space, Request $request): Response
    {
        $reader = $this->getUser();

        if (!$reader instanceof CoreUserInterface) {
            throw $this->createAccessDeniedException();
        }

        // Merged here rather than folded into the board's builder: the
        // conversation is a fifth thing the reader can be looking at, not a
        // fifth reading of the cards, and a builder named for the board has no
        // business knowing the chat exists.
        $response = $this->render('@Studio/backend/space-content/content.html.twig', [
            ...$this->viewBuilder->contentView($space),
            ...$this->chatViewBuilder->view($space, $reader),
            ...$this->notesViewBuilder->view($space),
            ...$this->filesViewBuilder->view($space),
            ...$this->informationViewBuilder->view($space),
            ...$this->resourcesViewBuilder->view($space),
        ]);

        // **Being signed in is not being authorised at the hub.** The hub has
        // no session and no idea who this is; the only thing it reads is a
        // short-lived JWT scoped to one topic, and this is where a reader who
        // may see the space is handed one. Without it the connection is
        // refused, and a refused connection looks exactly like a hub that is
        // down - which is how this line came to be missing long enough to be
        // noticed on screen rather than in a test.
        // Les canaux que ce lecteur entend, pas ceux de l'espace : le jeton
        // nomme ses sujets un par un, et un canal interne dont il n'est pas
        // n'y figure pas.
        $cookie = $this->chatHub->subscriptionCookie($request, $this->chatChannels->findForUser($space, $reader));

        if ($cookie instanceof Cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
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

        $documents = [];

        foreach ($this->attachmentRepository->findForItem($item) as $attachment) {
            $documents[] = $attachment->getDocument();
        }

        $this->itemManager->delete($item);

        return $this->jsonSuccess(
            $this->viewBuilder->boardPayload($space)
            + $this->orphanedOffer->payload($space, $documents, $this->isGranted('ged.documents.delete')),
        );
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

    /**
     * A file dropped on a card, filed in GED and shown on it.
     *
     * The destination category and the published status are decided by
     * {@see SpaceAttachmentUploader},
     * not by this request. A payload that could name its own category would let
     * a card drop a file into the contracts category, and one that could leave
     * it a draft would put a file on a card that is unfindable in GED ever
     * after.
     */
    #[Route('/content/{itemId}/attachments/upload', name: '_attachment_upload', requirements: ['itemId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function uploadAttachment(
        CustomerSpace $space,
        #[MapEntity(id: 'itemId')]
        SpaceContentItem $item,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $item->getSpace()->getId());

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->jsonInvalidInput(['file' => 'backend.studio.space_content.errors.attachment_required']);
        }

        // La même règle que sur une note et que sur un dépôt d'invité : ce qui
        // monte passe par la politique de l'administrateur. Sans elle, le seul
        // plafond était celui de PHP, et un type refusé partout ailleurs
        // entrait ici.
        $refusal = $this->uploadPolicies->forStaffDocuments()->refusalFor($file);

        if ($refusal instanceof UploadRefusalEnum) {
            return $this->jsonInvalidInput(['file' => match ($refusal) {
                UploadRefusalEnum::TooLarge => 'backend.ged.documents.errors.upload_too_large',
                UploadRefusalEnum::TypeRefused => 'backend.ged.documents.errors.upload_type_refused',
                UploadRefusalEnum::Broken => 'backend.ged.documents.errors.upload_failed',
            }]);
        }

        try {
            $this->attachments->uploadAsStudio($item, $file);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    /**
     * A document that is already in GED, put on a card.
     *
     * The picker's half: a logo, a press kit, a photo filed last month. Nothing
     * is uploaded and nothing is copied - two cards can show the same file, and
     * a file shown on a card is the same row GED lists.
     */
    #[Route('/content/{itemId}/attachments/attach', name: '_attachment_attach', requirements: ['itemId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function attachDocument(
        CustomerSpace $space,
        #[MapEntity(id: 'itemId')]
        SpaceContentItem $item,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $item->getSpace()->getId());

        $documentId = $this->decodeJson($request)['documentId'] ?? null;

        if (!is_numeric($documentId)) {
            return $this->jsonInvalidInput(['documentId' => 'backend.studio.space_content.errors.attachment_required']);
        }

        $document = $this->documents->find((int) $documentId);

        if (!$document instanceof Document) {
            return $this->jsonInvalidInput(['documentId' => 'backend.studio.space_content.errors.attachment_unknown']);
        }

        try {
            $this->attachments->attachAsStudio($item, $document);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->boardPayload($space));
    }

    /**
     * Takes a file off a card.
     *
     * The document stays in GED. This route is about what a card shows, not
     * about destroying an asset - deleting the file itself is GED's own screen,
     * where the consequences are spelled out and a trash catches mistakes.
     */
    #[Route('/attachments/{attachmentId}/detach', name: '_attachment_detach', requirements: ['attachmentId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function detachAttachment(
        CustomerSpace $space,
        #[MapEntity(id: 'attachmentId')]
        SpaceContentAttachment $attachment,
    ): JsonResponse {
        $this->assertOwned($space, $attachment->getItem()->getSpace()->getId());

        // Read before the row goes: afterwards there is no way back to it.
        $document = $attachment->getDocument();

        $this->attachments->detach($attachment);

        return $this->jsonSuccess(
            $this->viewBuilder->boardPayload($space)
            + $this->orphanedOffer->payload($space, [$document], $this->isGranted('ged.documents.delete')),
        );
    }

    /**
     * A file on one of this space's cards, read through the space.
     *
     * **Why not GED's own route.** A file uploaded through a space is filed as
     * a draft - which is what stops the public catch-all serving it to anybody
     * holding the address - and `DocumentUrlGenerator` addresses anything
     * unpublished through `backend_ged_files`, which asks for
     * `ged.documents.view`. A studio member who manages client spaces need not
     * hold that: gating the pictures on it would show them a board of broken
     * images and no reason why.
     *
     * So the board reads its own files through its own privilege, exactly as
     * the client reads theirs through their link. Same rule on both surfaces:
     * whatever grants the board grants what is on it.
     */
    #[Route(
        '/attachments/{attachmentId}/{variant}',
        name: '_attachment_file',
        requirements: ['attachmentId' => '\d+', 'variant' => 'file|preview'],
        defaults: ['variant' => 'file'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function attachmentFile(
        CustomerSpace $space,
        #[MapEntity(id: 'attachmentId')]
        SpaceContentAttachment $attachment,
        string $variant,
    ): Response {
        $this->assertOwned($space, $attachment->getItem()->getSpace()->getId());

        // Servi par le service commun : local déchargé par le serveur
        // web, distant diffusé par morceaux, privé une heure.
        return $this->responder->respond($this->keyOf($attachment->getDocument(), $variant));
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

    /**
     * La clé du fichier ou de sa vignette.
     *
     * Le service ne connaît pas les documents, et c'est voulu : il sert une
     * clé de stockage, quelle que soit la chose qui l'a produite.
     */
    private function keyOf(DocumentInterface $document, string $variant): string
    {
        $key = 'preview' === $variant
            ? ($document->getVariants()['thumbnail'] ?? $document->getThumbnailPath())
            : $document->getFilePath();

        if (null === $key || '' === $key) {
            throw $this->createNotFoundException();
        }

        return $key;
    }
}
