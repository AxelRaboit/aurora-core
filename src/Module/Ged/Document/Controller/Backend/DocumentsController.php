<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Ged\Document\Dto\DocumentInputFactoryInterface;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Ged\Document\Serializer\DocumentVersionSerializerInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Ged\Document\View\DocumentsViewBuilder;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/ged/documents', name: 'backend_ged_documents')]
#[IsGranted('ged.documents.view')]
final class DocumentsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly DocumentSerializerInterface $serializer,
        private readonly DocumentManagerInterface $manager,
        private readonly PayloadValidator $payloadValidator,
        private readonly DocumentsViewBuilder $viewBuilder,
        private readonly DocumentInputFactoryInterface $inputFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly DocumentVersionSerializerInterface $versionSerializer,
        private readonly GedDocumentUploader $uploader,
        private readonly DocumentUsageService $usageService,
        private readonly DocumentFolderRepository $folderRepository,
        private readonly InlineImageUploader $inlineImageUploader,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(PaginationRequest $pagination): Response
    {
        return $this->render('@Ged/backend/documents/index.html.twig', $this->viewBuilder->indexView($pagination));
    }

    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(Request $request, PaginationRequest $pagination): JsonResponse
    {
        $categoryId = $request->query->getInt('categoryId') ?: null;
        $tagId = $request->query->getInt('tagId') ?: null;
        $folderId = $request->query->getInt('folderId') ?: null;
        $statusValue = $request->query->getString('status');
        $status = '' !== $statusValue ? DocumentStatusEnum::tryFrom($statusValue) : null;
        $mimeGroupValue = $request->query->getString('mimeGroup');
        $mimeGroup = '' !== $mimeGroupValue ? MimeGroupEnum::tryFrom($mimeGroupValue) : null;
        // Media-style sidebar navigation: rootOnly=1 → docs with no folder.
        // Without rootOnly and without folderId, the listing stays cross-folder
        // (backwards-compatible with the filter-only callers).
        $rootOnly = $request->query->getBoolean('rootOnly');

        return $this->json($this->viewBuilder->buildListPayload($pagination, $categoryId, $tagId, $folderId, $status, $mimeGroup, $rootOnly));
    }

    #[Route('/{id}', name: '_show', methods: [HttpMethodEnum::Get->value])]
    public function show(Document $document): Response
    {
        return $this->render('@Ged/backend/documents/show.html.twig', [
            'document' => $this->serializer->serialize($document),
            'backPath' => $this->urlGenerator->generate('backend_ged_documents'),
            'updatePath' => $this->urlGenerator->generate('backend_ged_documents_update', ['id' => $document->getId()]),
            'deletePath' => $this->urlGenerator->generate('backend_ged_documents_delete', ['id' => $document->getId()]),
            'cropPath' => $this->urlGenerator->generate('backend_ged_documents_crop', ['id' => $document->getId()]),
            'listPath' => $this->urlGenerator->generate('backend_ged_documents'),
        ]);
    }

    #[Route('/{id}/versions', name: '_versions', methods: [HttpMethodEnum::Get->value])]
    public function versions(Document $document): JsonResponse
    {
        $versions = $this->versionRepository->findByDocument($document);

        return $this->json([
            'success' => true,
            'versions' => array_map($this->versionSerializer->serialize(...), $versions),
        ]);
    }

    #[Route('/{id}/usage', name: '_usage', methods: [HttpMethodEnum::Get->value])]
    public function usage(Document $document): JsonResponse
    {
        return $this->jsonSuccess($this->usageService->findUsages((int) $document->getId()));
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.create')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $document = $this->manager->create($input);

        return $this->jsonSuccess(['document' => $this->serializer->serialize($document)]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.edit')]
    public function update(Document $document, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($document, $input);

        return $this->jsonSuccess(['document' => $this->serializer->serialize($document)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.delete')]
    public function delete(Document $document): JsonResponse
    {
        $this->manager->delete($document);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/crop', name: '_crop', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.edit')]
    public function crop(Document $document, Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $this->manager->cropImage(
            $document,
            (int) ($data['x'] ?? 0),
            (int) ($data['y'] ?? 0),
            (int) ($data['width'] ?? 1),
            (int) ($data['height'] ?? 1),
        );

        return $this->jsonSuccess(['document' => $this->serializer->serialize($document)]);
    }

    #[Route('/bulk-delete', name: '_bulk_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.delete')]
    public function bulkDelete(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $ids = array_values(array_filter(array_map(intval(...), (array) ($payload['ids'] ?? []))));
        $count = $this->manager->bulkDelete($ids);

        return $this->jsonSuccess(['deleted' => $count]);
    }

    #[Route('/{id}/move', name: '_move', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.edit')]
    public function move(Document $document, Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $folderId = isset($data['folderId']) && (int) $data['folderId'] > 0 ? (int) $data['folderId'] : null;
        $folder = null !== $folderId ? $this->folderRepository->find($folderId) : null;

        $this->manager->move($document, $folder);

        return $this->jsonSuccess(['document' => $this->serializer->serialize($document)]);
    }

    #[Route('/bulk-move', name: '_bulk_move', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.edit')]
    public function bulkMove(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $ids = array_values(array_filter(array_map(intval(...), (array) ($data['ids'] ?? []))));
        $folderId = isset($data['folderId']) && (int) $data['folderId'] > 0 ? (int) $data['folderId'] : null;
        $folder = null !== $folderId ? $this->folderRepository->find($folderId) : null;

        $this->manager->bulkMove($ids, $folder);

        return $this->jsonSuccess();
    }

    /**
     * Uploads a file to GED storage (`var/uploads/ged/Y/m/<slug>-<uniq>.<ext>`)
     * without persisting any DB row yet. Returns the file metadata the form
     * carries into the `create` / `update` submit. Two-step pattern keeps
     * the form submit a regular JSON post.
     */
    #[Route('/upload', name: '_upload', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.create')]
    public function upload(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (null === $file) {
            return $this->jsonFailure('backend.ged.documents.errors.upload_required');
        }

        return $this->jsonSuccess($this->uploader->upload($file));
    }

    /**
     * One call for the pickers embedded in other forms - a banner image, a
     * featured image, a custom field.
     *
     * Separate from `/upload` because that one deliberately stops at the bytes
     * and leaves the Document row to GED's own create form, which asks for a
     * category, a folder and tags. An author picking a picture mid-edit has no
     * such form, and chaining the two calls from the browser left them filing
     * the result themselves - which meant nobody did.
     *
     * The destination is decided server-side by {@see InlineImageUploader}: a
     * request cannot name the category it lands in, or leave it a draft.
     */
    #[Route('/upload-image', name: '_upload_image', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.create')]
    public function uploadImage(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (null === $file) {
            return $this->jsonFailure('backend.ged.documents.errors.upload_required');
        }

        // Images only. The endpoint is reachable by anyone who may create a
        // document, and the pickers that call it show what they get back as an
        // <img> - a PDF would file silently and render as a broken picture.
        if (!str_starts_with((string) $file->getMimeType(), 'image/')) {
            return $this->jsonFailure('backend.ged.documents.errors.image_required');
        }

        return $this->jsonSuccess([
            'document' => $this->serializer->serialize($this->inlineImageUploader->upload($file)),
        ]);
    }
}
