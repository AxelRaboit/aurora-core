<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentFolder\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Ged\DocumentFolder\Dto\DocumentFolderInputFactoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Manager\DocumentFolderManagerInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\DocumentFolder\Serializer\DocumentFolderSerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/ged/folders', name: 'backend_ged_folders')]
#[IsGranted('ged.folders.manage')]
final class DocumentFoldersController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly DocumentFolderSerializerInterface $serializer,
        private readonly DocumentFolderManagerInterface $manager,
        private readonly PayloadValidator $payloadValidator,
        private readonly DocumentFolderRepository $folderRepository,
        private readonly DocumentFolderInputFactoryInterface $inputFactory,
    ) {}

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $folder = $this->manager->create($input);

        return $this->jsonSuccess(['folder' => $this->serializer->serialize($folder), 'folders' => $this->allFolders()]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(DocumentFolder $folder, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($folder, $input);

        return $this->jsonSuccess(['folder' => $this->serializer->serialize($folder), 'folders' => $this->allFolders()]);
    }

    /**
     * Moves a folder to the trash.
     *
     * `cascade` defaults to true: the caller that says nothing gets the
     * reversible version, where the branch comes back as it was. A screen that
     * wants the old behaviour - contents released at the root - has to ask for
     * it, because that is the one a restore cannot undo.
     */
    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(DocumentFolder $folder, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $this->manager->delete($folder, !array_key_exists('cascade', $payload) || (bool) $payload['cascade']);

        return $this->jsonSuccess(['folders' => $this->allFolders()]);
    }

    #[Route('/{id}/restore', name: '_restore', methods: [HttpMethodEnum::Post->value])]
    public function restore(DocumentFolder $folder): JsonResponse
    {
        $this->manager->restore($folder);

        return $this->jsonSuccess(['folders' => $this->allFolders()]);
    }

    #[Route('/{id}/force-delete', name: '_force_delete', methods: [HttpMethodEnum::Post->value])]
    public function forceDelete(DocumentFolder $folder): JsonResponse
    {
        $this->manager->forceDelete($folder);

        return $this->jsonSuccess(['folders' => $this->allFolders()]);
    }

    /**
     * Destroys every folder waiting in the trash.
     *
     * Destroys the folders and nothing else: what fell with one is released to
     * the root, exactly as a single permanent deletion does, because the
     * documents are the part nobody asked to lose. Emptying the document trash
     * is what removes those.
     */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        $deleted = 0;
        foreach ($this->folderRepository->findTrashedRoots() as $folder) {
            $this->manager->forceDelete($folder);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted, 'folders' => $this->allFolders()]);
    }

    #[Route('/{id}/move', name: '_move', methods: [HttpMethodEnum::Post->value])]
    public function move(DocumentFolder $folder, Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $parentId = $data['parentId'] ?? null;
        $newParent = null !== $parentId ? $this->folderRepository->find((int) $parentId) : null;

        if (!$this->manager->move($folder, $newParent)) {
            return $this->jsonInvalidInput(['parentId' => 'backend.ged.folders.errors.cycle']);
        }

        return $this->jsonSuccess(['folders' => $this->allFolders()]);
    }

    #[Route('/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorder(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $orderedIds = array_map(intval(...), $data['ids'] ?? []);

        $this->manager->reorder($orderedIds);

        return $this->jsonSuccess(['folders' => $this->allFolders()]);
    }

    private function allFolders(): array
    {
        return array_map($this->serializer->serialize(...), $this->folderRepository->findAllOrdered());
    }
}
