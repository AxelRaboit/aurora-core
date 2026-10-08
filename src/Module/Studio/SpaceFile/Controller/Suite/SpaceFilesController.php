<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Security\ClientVisibility;
use Aurora\Module\Studio\SpaceContent\Service\SpaceOrphanedDocumentOffer;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceFile\Manager\SpaceFileManagerInterface;
use Aurora\Module\Studio\SpaceFile\View\SpaceFilesViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_numeric;

/**
 * The space's files, the ones that are on no record.
 *
 * **Hidden from the client until they are shown to them**, like everything a
 * space can show them. The brand guide, the logos, the brief, a signed PDF:
 * this is where what is handed to them and what is worked from is stored,
 * and showing either one requires the right to share the space.
 *
 * Each route names the space and checks that what it was given belongs to
 * it: the file arrives by its id, so nothing stops a crafted request from
 * pointing at another client's.
 */
#[Route('/workspace/{id}/files', name: 'workspace_space_files', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceFilesController extends AbstractController
{
    use SpaceOwnershipTrait;
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceFileManagerInterface $files,
        protected readonly SpaceFilesViewBuilder $viewBuilder,
        protected readonly DocumentRepository $documentRepository,
        protected readonly SpaceOrphanedDocumentOffer $orphanedOffer,
        protected readonly StoredFileResponder $responder,
        protected readonly UploadPolicyProvider $uploadPolicyProvider,
    ) {}

    /**
     * A file dropped on the space.
     *
     * The same uploader as on a record, so the same folder and the same draft:
     * the category and the status are decided there, never by the request - a
     * form that named its category could drop into the contracts one.
     */
    #[Route('/upload', name: '_upload', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function upload(CustomerSpace $space, Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->jsonInvalidInput(['file' => 'suite.studio.space_files.errors.required']);
        }

        // The same rule as on a note and on a guest drop: what is uploaded
        // goes through the administrator's policy. Without it, the only cap
        // was PHP's, and a type refused everywhere else got in here.
        $refusal = $this->uploadPolicyProvider->forStaffDocuments()->refusalFor($file);

        if ($refusal instanceof UploadRefusalEnum) {
            return $this->jsonInvalidInput(['file' => match ($refusal) {
                UploadRefusalEnum::TooLarge => 'suite.ged.documents.errors.upload_too_large',
                UploadRefusalEnum::TypeRefused => 'suite.ged.documents.errors.upload_type_refused',
                UploadRefusalEnum::Broken => 'suite.ged.documents.errors.upload_failed',
            }]);
        }

        try {
            $this->files->uploadAsStudio($space, $file);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * A document already in the media library, attached to the space.
     *
     * The "picker" half: nothing is uploaded and nothing is copied. Two spaces
     * can carry the same file, and it is the row the media library lists.
     */
    #[Route('/attach', name: '_attach', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function attach(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $documentId = $payload['documentId'] ?? null;

        if (!is_numeric($documentId)) {
            return $this->jsonInvalidInput(['documentId' => 'suite.studio.space_files.errors.required']);
        }

        // Taken only for someone who can browse the media library: a number
        // can be guessed as easily as it is picked, and a file attached to the
        // space is shown to the client. Same response as an unknown number.
        $document = $this->isGranted('ged.documents.view') ? $this->documentRepository->find((int) $documentId) : null;

        if (!$document instanceof DocumentInterface) {
            return $this->jsonInvalidInput(['documentId' => 'suite.studio.space_files.errors.unknown']);
        }

        try {
            $this->files->attachAsStudio($space, $document);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * Removes the file from the space, and leaves the document.
     *
     * The response carries what nothing uses any more, so the screen offers
     * the trash instead of deciding for it: the same contract as a record's
     * attachments and a note's images.
     */
    #[Route('/{fileId}/remove', name: '_remove', requirements: ['fileId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function remove(
        CustomerSpace $space,
        #[MapEntity(id: 'fileId')]
        SpaceFile $file,
    ): JsonResponse {
        $this->assertOwned($space, $file->getSpace()->getId());

        $document = $file->getDocument();

        $this->files->remove($file);

        return $this->jsonSuccess([
            ...$this->viewBuilder->payload($space),
            ...$this->orphanedOffer->payload($space, [$document], $this->isGranted('ged.documents.delete')),
        ]);
    }

    /**
     * Shows the file to the client, or hides it from them.
     *
     * Under the right to share the space, on top of the right to edit it:
     * showing a file to the client is sending it to them.
     */
    #[Route('/{fileId}/visibility', name: '_visibility', requirements: ['fileId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    #[IsGranted(ClientVisibility::PRIVILEGE)]
    public function visibility(
        CustomerSpace $space,
        #[MapEntity(id: 'fileId')]
        SpaceFile $file,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $file->getSpace()->getId());

        try {
            $this->files->setVisibleToClient($file, true === ($this->decodeJson($request)['visible'] ?? false));
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * The file itself, read through the space.
     *
     * Not through the media library route: a file dropped here is a draft,
     * which `DocumentUrlGenerator` addresses through `suite_ged_files`, which
     * requires `ged.documents.view`. Someone who manages customer spaces does
     * not necessarily have that privilege, and requiring it would show a list
     * of unreadable files without saying why. What opens the space opens
     * what is inside it.
     */
    #[Route(
        '/{fileId}/{variant}',
        name: '_file',
        requirements: ['fileId' => '\d+', 'variant' => 'file|preview'],
        defaults: ['variant' => 'file'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(
        CustomerSpace $space,
        #[MapEntity(id: 'fileId')]
        SpaceFile $file,
        string $variant,
    ): Response {
        $this->assertOwned($space, $file->getSpace()->getId());

        return $this->responder->respond($this->keyOf($file->getDocument(), $variant));
    }

    /**
     * The key of the file or of its thumbnail.
     *
     * The service does not know about documents, on purpose: it serves a
     * storage key, whatever produced it.
     */
    private function keyOf(DocumentInterface $document, string $variant): string
    {
        $key = 'preview' === $variant
            ? ($document->getRenditions()['thumbnail'] ?? $document->getThumbnailPath())
            : $document->getFilePath();

        if (null === $key || '' === $key) {
            throw $this->createNotFoundException();
        }

        return $key;
    }
}
