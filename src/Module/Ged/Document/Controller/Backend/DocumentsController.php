<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Service\VideoCapture;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Configuration\Storage\Setting\StorageSettings;
use Aurora\Module\Ged\Document\Dto\DocumentInputFactoryInterface;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Message\RelocateDocumentMessage;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Ged\Document\Serializer\DocumentVersionSerializerInterface;
use Aurora\Module\Ged\Document\Service\DocumentRelocator;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Ged\Document\View\DocumentsViewBuilder;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Ged\Enum\DocumentTransferStateEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/ged/documents', name: 'backend_ged_documents')]
#[IsGranted('ged.documents.view')]
final class DocumentsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    /**
     * Above this, a move goes to a worker rather than holding the request.
     *
     * Chosen so the common case stays instant: an image and its variants sit
     * far below, a scanned contract usually too. It is a video, or a document
     * with a long history of versions, that crosses it.
     */
    private const int INLINE_RELOCATION_LIMIT_BYTES = 8 * 1024 * 1024;

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
        private readonly DocumentRelocator $relocator,
        private readonly MessageBusInterface $messageBus,
        private readonly StorageSettings $storageSettings,
        private readonly DocumentRepository $documentRepository,
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

        $diskValue = $request->query->getString('storageDisk');
        $storageDisk = '' !== $diskValue ? StorageDiskEnum::tryFrom($diskValue) : null;

        // The trash is a view of this same listing, so it travels as a filter
        // rather than as a screen of its own: every other filter keeps working
        // inside it, and there is one payload shape to keep in sync.
        $trashed = $request->query->getBoolean('trashed');

        return $this->json($this->viewBuilder->buildListPayload($pagination, $categoryId, $tagId, $folderId, $status, $mimeGroup, $rootOnly, $storageDisk, $trashed));
    }

    /**
     * The folder tree, for the side menu's GED panel.
     *
     * Declared above `/{id}` because that one would happily match `folders` -
     * the same reason `/list` sits where it does.
     *
     * Its own endpoint rather than a slice of `/list`: the panel wants the tree
     * and nothing else, on pages that have no document listing at all (tags,
     * categories, folders), and asking for a paginated document page to throw
     * it away would be a query per navigation for nothing.
     *
     * Behind `ged.documents.view` like the rest of this controller, which is
     * the right gate: every row in the tree is a link into the document
     * listing, so somebody who cannot see documents has nothing to click.
     */
    #[Route('/folders', name: '_folders', methods: [HttpMethodEnum::Get->value])]
    public function folders(): JsonResponse
    {
        return $this->json($this->viewBuilder->folderTreePayload());
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
            'storagePath' => $this->urlGenerator->generate('backend_ged_documents_storage', ['id' => $document->getId()]),
            'storageRelocationAvailable' => $this->storageSettings->isRelocationAvailable(),
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

    /**
     * Brings a document back from the trash.
     *
     * Under `delete` rather than `edit`: restoring undoes a deletion, and the
     * person trusted with the trash is the one trusted to have emptied it.
     */
    #[Route('/{id}/restore', name: '_restore', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.delete')]
    public function restore(Document $document): JsonResponse
    {
        $this->manager->restore($document);

        return $this->jsonSuccess();
    }

    /**
     * Deletes a document for good, file included.
     *
     * Separate from `/delete` because it is a different promise: that one is
     * reversible, this one takes the bytes with it.
     */
    #[Route('/{id}/force-delete', name: '_force_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.delete')]
    public function forceDelete(Document $document): JsonResponse
    {
        $this->manager->forceDelete($document);

        return $this->jsonSuccess();
    }

    #[Route('/bulk-restore', name: '_bulk_restore', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.delete')]
    public function bulkRestore(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $ids = array_values(array_filter(array_map(intval(...), (array) ($payload['ids'] ?? []))));

        return $this->jsonSuccess(['restored' => $this->manager->bulkRestore($ids)]);
    }

    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.delete')]
    public function emptyTrash(): JsonResponse
    {
        return $this->jsonSuccess(['deleted' => $this->manager->emptyTrash()]);
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
     * Moves one document's bytes to the other storage backend.
     *
     * Small ones are done inline, so the row updates while the reader is still
     * looking at it. Above the threshold the work is handed to a worker: a
     * browser should not be held open on a bucket, and a request that times out
     * half way through a copy is the one case the ordering in
     * {@see DocumentRelocator} cannot make pretty.
     *
     * Its own privilege rather than `edit`: moving bytes between backends
     * spends transfer and request budget on somebody's account, which is not
     * the same permission as fixing a typo in a title.
     */
    #[Route('/{id}/storage', name: '_storage', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.relocate')]
    public function relocate(Document $document, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $target = StorageDiskEnum::tryFrom((string) ($payload['disk'] ?? ''));

        if (!$target instanceof StorageDiskEnum) {
            return $this->jsonFailure('backend.ged.documents.errors.unknown_disk');
        }

        if (DocumentTransferStateEnum::Pending === $document->getStorageTransferState()) {
            return $this->jsonFailure('backend.ged.documents.errors.relocation_busy');
        }

        if ($this->relocator->weigh($document) > self::INLINE_RELOCATION_LIMIT_BYTES) {
            $this->messageBus->dispatch(new RelocateDocumentMessage((int) $document->getId(), $target));

            return $this->jsonSuccess(['queued' => true, 'state' => DocumentTransferStateEnum::Pending->value]);
        }

        $relocation = $this->relocator->relocate($document, $target);

        if ($relocation->busy) {
            return $this->jsonFailure('backend.ged.documents.errors.relocation_busy');
        }

        if (!$relocation->ok) {
            return $this->jsonFailure('backend.ged.documents.errors.relocation_failed', extra: [
                'reason' => $relocation->error,
            ]);
        }

        return $this->jsonSuccess([
            'queued' => false,
            'disk' => $document->getStorageDisk()->value,
            'state' => $document->getStorageTransferState()->value,
            'filesMoved' => $relocation->filesMoved,
            'alreadyThere' => $relocation->alreadyThere,
        ]);
    }

    /**
     * The same move, over a selection.
     *
     * Reports counts rather than a single success, because a selection can
     * legitimately be a mix: some documents already where they were asked to
     * go, one held by a move still running, one whose backend refused. Telling
     * the reader "done" over that would be a lie, and telling them "failed"
     * would be another.
     */
    #[Route('/bulk-storage', name: '_bulk_storage', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.relocate')]
    public function bulkRelocate(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $target = StorageDiskEnum::tryFrom((string) ($payload['disk'] ?? ''));

        if (!$target instanceof StorageDiskEnum) {
            return $this->jsonFailure('backend.ged.documents.errors.unknown_disk');
        }

        /** @var list<int> $ids */
        $ids = array_values(array_filter(array_map(intval(...), (array) ($payload['ids'] ?? []))));

        $counts = ['moved' => 0, 'queued' => 0, 'alreadyThere' => 0, 'busy' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $document = $this->documentRepository->find($id);

            if (null === $document) {
                continue;
            }

            if ($this->relocator->weigh($document) > self::INLINE_RELOCATION_LIMIT_BYTES) {
                $this->messageBus->dispatch(new RelocateDocumentMessage($id, $target));
                ++$counts['queued'];

                continue;
            }

            $relocation = $this->relocator->relocate($document, $target);

            ++$counts[match (true) {
                $relocation->alreadyThere => 'alreadyThere',
                $relocation->busy => 'busy',
                $relocation->ok => 'moved',
                default => 'failed',
            }];
        }

        return $this->jsonSuccess($counts);
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

        return $this->jsonSuccess($this->uploader->upload($file, $this->videoCapture($request)));
    }

    /**
     * The frame the browser drew from a film before sending it, when it sent
     * one.
     *
     * Nothing here decides that the upload is a video: the uploader does, from
     * the file's own mime type. A capture posted alongside a PDF is read and
     * then ignored, which is the only sane reading of a field that does not
     * apply.
     */
    private function videoCapture(Request $request): ?VideoCapture
    {
        /** @var UploadedFile|null $poster */
        $poster = $request->files->get('poster');

        if (null === $poster) {
            return null;
        }

        return new VideoCapture(
            $poster,
            $request->request->getInt('videoWidth') ?: null,
            $request->request->getInt('videoHeight') ?: null,
        );
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
