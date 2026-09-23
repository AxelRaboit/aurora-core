<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Notes\Folder\Dto\NoteFolderInputFactoryInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Manager\NoteFolderManagerInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Folder\Serializer\NoteFolderSerializerInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;

/**
 * The folders of the person asking, and nobody else's.
 *
 * Declared under `/folders` rather than under `/{id}`, which the note
 * controller owns with a numeric requirement: the two never collide, and a
 * folder route reads as what it is.
 */
#[Route('/backend/notes/markdown/folders', name: 'backend_notes_markdown_folders')]
#[IsGranted('notes.markdown.use')]
final class NoteFoldersController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly NoteFolderRepository $repository,
        private readonly NoteFolderManagerInterface $manager,
        private readonly NoteFolderInputFactoryInterface $inputFactory,
        private readonly NoteFolderSerializerInterface $serializer,
        private readonly PayloadValidator $payloadValidator,
    ) {}

    /**
     * The whole tree, flat, with what each folder holds.
     *
     * Flat rather than nested for the same reason the notes are: the browser
     * rebuilds the tree from `parentId`, and a flat list is what a panel, a
     * breadcrumb and a move dialog each need a different shape of.
     */
    #[Route('', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->jsonSuccess(['folders' => $this->serializeAllFor($user)]);
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $folder = $this->manager->create($user, $input);

        return $this->jsonSuccess(['folder' => $this->serializer->serialize($folder)]);
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->repository->findOneByUserAndId($user, $id);
        if (!$folder instanceof NoteFolderInterface) {
            return $this->jsonNotFound();
        }

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($folder, $input);

        return $this->jsonSuccess(['folder' => $this->serializer->serialize($folder)]);
    }

    /**
     * Refiles a folder, or refuses to.
     *
     * The two refusals are the manager's, not this method's: a cycle takes
     * both branches off every screen, and a branch past the depth limit
     * cannot be shown in a breadcrumb. Either way nothing is written and the
     * answer says which.
     */
    #[Route('/{id}/move', name: '_move', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function move(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->repository->findOneByUserAndId($user, $id);
        if (!$folder instanceof NoteFolderInterface) {
            return $this->jsonNotFound();
        }

        $raw = $this->decodeJson($request)['parentId'] ?? null;

        $parent = null;
        if (null !== $raw && '' !== $raw) {
            $parent = $this->repository->findOneByUserAndId($user, (int) $raw);
            if (!$parent instanceof NoteFolderInterface) {
                return $this->jsonNotFound();
            }
        }

        if (!$this->manager->move($folder, $parent)) {
            return $this->jsonFailure('refused', extra: ['message' => 'notes.markdown.folders.errors.move_refused']);
        }

        return $this->jsonSuccess(['folder' => $this->serializer->serialize($folder)]);
    }

    /** Épingler un dossier au menu, ou l'en décrocher. */
    #[Route('/{id}/favorite', name: '_favorite', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function favorite(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->repository->findOneByUserAndId($user, $id);
        if (!$folder instanceof NoteFolderInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['favorite' => $this->manager->toggleFavorite($folder)]);
    }

    /**
     * Ouvre ou referme ce dossier au reste du back-office.
     *
     * Seul son propriétaire décide : la recherche passe par
     * `findOneByUserAndId`, donc partager le dossier d'un collègue répond
     * 404 comme n'importe quel dossier qui n'est pas à soi.
     */
    #[Route('/{id}/share', name: '_share', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function share(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->repository->findOneByUserAndId($user, $id);
        if (!$folder instanceof NoteFolderInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['shared' => $this->manager->toggleShared($folder)]);
    }

    /** Sends a folder to the trash, with everything inside it. */
    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->repository->findOneByUserAndId($user, $id);
        if (!$folder instanceof NoteFolderInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->delete($folder);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->repository->findOneByUserAndId($user, $id);
        if (!$folder instanceof NoteFolderInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->restore($folder);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/force-delete', name: '_force_delete', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function forceDelete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->repository->findOneByUserAndId($user, $id);
        if (!$folder instanceof NoteFolderInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->forceDelete($folder);

        return $this->jsonSuccess();
    }

    /**
     * Destroys every folder this person has in the trash.
     *
     * Their own only: a folder belongs to its author, and there is no view
     * in which emptying one person's trash should reach another's.
     */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $deleted = 0;
        foreach ($this->repository->findTrashedRootsForUser($user) as $folder) {
            $this->manager->forceDelete($folder);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    #[Route('/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorder(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $raw = $this->decodeJson($request)['entries'] ?? null;

        if (!is_array($raw)) {
            return $this->jsonInvalidInput(['entries' => 'notes.markdown.folders.errors.bad_payload']);
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!isset($entry['id'])) {
                continue;
            }

            $parentId = $entry['parentId'] ?? null;

            $entries[] = [
                'id' => (int) $entry['id'],
                'parentId' => null === $parentId || '' === $parentId ? null : (int) $parentId,
                'position' => (int) ($entry['position'] ?? 0),
            ];
        }

        $this->manager->reorder($user, $entries);

        return $this->jsonSuccess(['folders' => $this->serializeAllFor($user)]);
    }

    /** @return list<array<string, mixed>> */
    private function serializeAllFor(CoreUserInterface $user): array
    {
        $serializer = $this->serializer->withCounts(
            $this->repository->countNotesPerFolderForUser($user),
            $this->repository->countChildrenPerFolderForUser($user),
        );

        return array_map(
            $serializer->serialize(...),
            $this->repository->findAllForUser($user),
        );
    }
}
