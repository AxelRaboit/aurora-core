<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteReorderInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Serializer\MarkdownNoteSerializerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteHierarchyService;
use Aurora\Module\Notes\Markdown\View\MarkdownNotesViewBuilder;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/notes/markdown', name: 'backend_notes_markdown')]
#[IsGranted('notes.markdown.use')]
final class MarkdownNotesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly MarkdownNoteSerializerInterface $serializer,
        private readonly MarkdownNoteManagerInterface $manager,
        private readonly MarkdownNoteRepository $repository,
        private readonly MarkdownNoteInputFactoryInterface $inputFactory,
        private readonly MarkdownNoteReorderInputFactoryInterface $reorderInputFactory,
        private readonly PayloadValidator $payloadValidator,
        private readonly MarkdownNotesViewBuilder $viewBuilder,
        private readonly MarkdownNoteHierarchyService $hierarchy,
    ) {}

    /**
     * Backend page render - mounts the Vue MarkdownNotesApp with the URL
     * map preloaded by the view builder. Initial note list is fetched
     * client-side via the JSON list endpoint.
     */
    /**
     * Sends the reader to their first note, so a note has one address and the
     * listing has none of its own. Renders the empty state when there is no
     * note to send them to.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $notes = $this->repository->findFlatListForUser($user);
        $first = $notes[0]['id'] ?? null;

        if (null !== $first) {
            return $this->redirectToRoute('backend_notes_markdown_show', ['id' => $first]);
        }

        return $this->render('@Notes/backend/markdown/index.html.twig', $this->viewBuilder->indexView($user));
    }

    /**
     * Flat list of all the current user's notes (no content). The Vue
     * frontend rebuilds the tree from parent_id + position.
     */
    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->jsonSuccess(['notes' => $this->repository->findFlatListForUser($user)]);
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

        $note = $this->manager->create($user, $input);

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($note, $input);

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->delete($note);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/move', name: '_move', methods: [HttpMethodEnum::Post->value])]
    public function move(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $data = $this->decodeJson($request);
        $parentId = isset($data['parentId']) ? (int) $data['parentId'] : null;

        $parent = null;
        if (null !== $parentId) {
            $parent = $this->repository->findOneByUserAndId($user, $parentId);
            if (!$parent instanceof MarkdownNoteInterface) {
                return $this->jsonNotFound();
            }

            if ($this->hierarchy->wouldCreateCycle($note, $parent)) {
                return $this->jsonFailure('cycle', extra: ['message' => 'Cannot move a note under one of its descendants.']);
            }
        }

        $this->manager->move($note, $parent);

        return $this->jsonSuccess(['note' => $this->serializer->serializeListItem($note)]);
    }

    #[Route('/{id}/backlinks', name: '_backlinks', methods: [HttpMethodEnum::Get->value])]
    public function backlinks(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['backlinks' => $this->manager->backlinks($user, $note)]);
    }

    #[Route('/{id}/unlinked-mentions', name: '_unlinked_mentions', methods: [HttpMethodEnum::Get->value])]
    public function unlinkedMentions(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['mentions' => $this->manager->unlinkedMentions($user, $note)]);
    }

    #[Route('/graph', name: '_graph', methods: [HttpMethodEnum::Get->value])]
    public function graph(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->jsonSuccess($this->manager->graph($user));
    }

    #[Route('/search', name: '_search', methods: [HttpMethodEnum::Get->value])]
    public function search(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();
        $query = (string) $request->query->get('q', '');

        return $this->jsonSuccess(['ids' => $this->manager->searchContent($user, $query)]);
    }

    #[Route('/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorder(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->reorderInputFactory->fromArray($this->decodeJson($request));

        try {
            $this->manager->reorder($user, $input->entries);
        } catch (InvalidArgumentException) {
            return $this->jsonFailure('cycle', extra: ['message' => 'Reorder would create a cycle.']);
        }

        return $this->jsonSuccess();
    }

    /**
     * Declared after the static GET routes (/list, /graph) so the router
     * matches those first - otherwise /{id} with id="graph" would shadow them.
     */
    /**
     * One address, two answers: the note's content for the page's own XHR, and
     * the whole page for somebody arriving from a link or the side menu.
     *
     * `X-Requested-With` is the contract - the same one `AuditController` uses,
     * and the reason `convention_no_raw_fetch` exists: every call through
     * `useRequest` sends it, so the JSON callers here are unaffected while a
     * plain navigation now gets a page instead of a JSON blob.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function show(int $id, Request $request): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);

        if (!$request->isXmlHttpRequest()) {
            if (!$note instanceof MarkdownNoteInterface) {
                throw $this->createNotFoundException();
            }

            return $this->render('@Notes/backend/markdown/index.html.twig', $this->viewBuilder->indexView($user, $id));
        }

        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }
}
