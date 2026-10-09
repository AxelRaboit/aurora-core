<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Comment\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\Comment\Entity\NoteCommentInterface;
use Aurora\Module\Notes\Comment\Service\NoteComments;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_int;
use function is_string;

/**
 * The comments of a note in the suite (09/10/2026).
 *
 * Whoever reads a note comments on it: a comment changes nothing in the
 * text. Settling a thread is for whoever writes the note, or wrote the
 * thread; removing a comment, for its author or whoever writes the note.
 */
#[Route('/suite/notes/markdown', name: 'suite_notes_markdown_comments')]
#[IsGranted('notes.markdown.use')]
final class NoteCommentsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly NoteComments $comments,
        private readonly NoteSpaceAccess $spaceAccess,
    ) {}

    #[Route('/{id}/comments', name: '', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value, HttpMethodEnum::Post->value])]
    public function comments(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        if ($request->isMethod(HttpMethodEnum::Post->value)) {
            $payload = $this->decodeJson($request);
            $body = is_string($payload['body'] ?? null) ? mb_trim($payload['body']) : '';
            if ('' === $body) {
                return $this->jsonInvalidInput(['body' => 'notes.markdown.comments.empty']);
            }

            $parent = null;
            if (is_int($payload['parentId'] ?? null)) {
                $parent = $this->comments->find($payload['parentId']);
                if (!$parent instanceof NoteCommentInterface || $parent->getNote()->getId() !== $note->getId()) {
                    return $this->jsonNotFound();
                }
            }

            $quote = is_string($payload['quote'] ?? null) ? $payload['quote'] : null;
            $this->comments->add($note, $user, null, $quote, $body, $parent);
        }

        return $this->jsonSuccess(['threads' => $this->comments->threads($note, $user)]);
    }

    #[Route('/comments/{commentId}/resolve', name: '_resolve', requirements: ['commentId' => '\d+|__comment__'], methods: [HttpMethodEnum::Post->value])]
    public function resolve(int $commentId, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $comment = $this->comments->find($commentId);
        if (!$comment instanceof NoteCommentInterface || !$this->allowed($user, $comment, true)) {
            return $this->jsonNotFound();
        }

        $this->comments->resolve($comment, true === ($this->decodeJson($request)['resolved'] ?? true));

        return $this->jsonSuccess(['threads' => $this->comments->threads($comment->getNote(), $user)]);
    }

    #[Route('/comments/{commentId}/delete', name: '_delete', requirements: ['commentId' => '\d+|__comment__'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $commentId): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $comment = $this->comments->find($commentId);
        if (!$comment instanceof NoteCommentInterface || !$this->allowed($user, $comment, false)) {
            return $this->jsonNotFound();
        }

        $note = $comment->getNote();
        $this->comments->delete($comment);

        return $this->jsonSuccess(['threads' => $this->comments->threads($note, $user)]);
    }

    /**
     * The comment's author, or whoever writes its note - and never on a note
     * one no longer reads. Settling a thread is also its first author's.
     */
    private function allowed(CoreUserInterface $user, NoteCommentInterface $comment, bool $threadAuthorToo): bool
    {
        $noteId = (int) $comment->getNote()->getId();
        if (!$this->spaceAccess->readableNote($user, $noteId) instanceof MarkdownNoteInterface) {
            return false;
        }

        $author = $comment->getAuthor();
        $root = $comment->getParent() ?? $comment;
        $threadAuthor = $root->getAuthor();
        if ($author instanceof CoreUserInterface && $author->getId() === $user->getId()) {
            return true;
        }

        if ($threadAuthorToo && $threadAuthor instanceof CoreUserInterface && $threadAuthor->getId() === $user->getId()) {
            return true;
        }

        return $this->spaceAccess->writableNote($user, $noteId) instanceof MarkdownNoteInterface;
    }
}
