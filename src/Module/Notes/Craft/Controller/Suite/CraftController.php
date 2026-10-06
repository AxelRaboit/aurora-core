<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\Craft\Service\CraftClient;
use Aurora\Module\Notes\Craft\Service\CraftNoteImporter;
use Aurora\Module\Notes\Favorite\Manager\NoteFavoriteManagerInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Serializer\MarkdownNoteSerializerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteHistory;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_numeric;
use function mb_trim;

/**
 * Import a Craft document into a notes space, and update it there again.
 *
 * **The rules are those of an ordinary note.** Importing means creating a
 * note: you must be able to write in the space (and in the folder) where it
 * lands. Refreshing means rewriting it: you must be able to write the note. A
 * space or a note out of reach answers 404, as everywhere in the module.
 */
#[Route('/suite/notes/craft', name: 'suite_notes_craft')]
#[IsGranted('notes.markdown.use')]
final class CraftController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly CraftClient $craft,
        private readonly CraftNoteImporter $importer,
        private readonly NoteSpaceAccess $spaceAccess,
        private readonly MarkdownNoteSerializerInterface $serializer,
        private readonly NoteFavoriteManagerInterface $favorites,
        private readonly MarkdownNoteHistory $history,
    ) {}

    /**
     * What the Craft connection lets you see.
     *
     * A short list, on purpose: the connection only carries the documents
     * designated in Craft, which is the only way to keep a token placed on a
     * rented server from opening a whole body of personal knowledge.
     *
     * `configured` rather than a silent empty list: a screen that offers
     * nothing must be able to say whether it is because the integration is off
     * or because the connection is empty.
     */
    #[Route('/documents', name: '_documents', methods: [HttpMethodEnum::Get->value])]
    public function documents(): JsonResponse
    {
        $documents = $this->craft->documents();

        return $this->jsonSuccess([
            'configured' => $this->craft->isConfigured(),
            // Three states, not two: off, unreachable, and open but empty.
            // Each one is fixed in a different place.
            'reachable' => null !== $documents,
            'documents' => $documents ?? [],
        ]);
    }

    /**
     * A Craft document, turned into a note of the requested space.
     *
     * The title comes from the list and not from the body: it is the one
     * Craft displays, and the rendered Markdown rarely starts with it.
     */
    #[Route('/import', name: '_import', methods: [HttpMethodEnum::Post->value])]
    public function import(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $payload = $this->decodeJson($request);
        $documentId = mb_trim((string) ($payload['documentId'] ?? ''));
        $title = mb_trim((string) ($payload['title'] ?? ''));

        if ('' === $documentId || '' === $title) {
            return $this->jsonFailure('notes.craft.errors.document_required');
        }

        // The folder imposes its space; without a folder, the requested
        // space, and failing that the personal space - the same rule as a creation.
        $folderId = is_numeric($payload['folderId'] ?? null) ? (int) $payload['folderId'] : null;
        $folder = null === $folderId ? null : $this->spaceAccess->writableFolder($user, $folderId);
        $spaceId = is_numeric($payload['spaceId'] ?? null) ? (int) $payload['spaceId'] : null;
        $space = match (true) {
            $folder instanceof NoteFolderInterface => $folder->getSpace(),
            null !== $folderId => null,
            null !== $spaceId => $this->spaceAccess->writableSpace($user, $spaceId),
            default => $this->spaceAccess->personalSpace($user),
        };

        if (!$space instanceof NoteSpaceInterface) {
            return $this->jsonNotFound();
        }

        $note = $this->importer->import($space, $folder, $user, $documentId, $title);

        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonFailure('notes.craft.errors.unreachable');
        }

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    /**
     * The note, brought back to the current version of its Craft document.
     *
     * **It replaces.** The screen asks for confirmation first, and the
     * replaced state goes into the note's history, where it can be brought
     * back from: a note is a copy, refreshing it makes the copy again.
     */
    #[Route('/{id}/refresh', name: '_refresh', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function refresh(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);

        if (!$note instanceof MarkdownNoteInterface || $note->isTrashed()) {
            return $this->jsonNotFound();
        }

        if (null === $note->getCraftDocumentId()) {
            return $this->jsonFailure('notes.craft.errors.not_imported');
        }

        // Kept no matter what, and not according to the usual gap between two
        // versions: what is replaced here comes from a hand, not from an
        // automatic save.
        $this->history->keep($note, $user);

        if (!$this->importer->refresh($note)) {
            return $this->jsonFailure('notes.craft.errors.unreachable');
        }

        return $this->jsonSuccess([
            'note' => $this->serializer->withFavorites($this->favorites->mapFor($user)['notes'])->serializeDetail($note),
        ]);
    }
}
