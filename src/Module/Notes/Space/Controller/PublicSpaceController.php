<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Markdown\View\MarkdownNotesViewBuilder;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;

/**
 * A published space, read without an account.
 *
 * **Everything starts from the space's address**, and nothing in the request
 * widens what it opens: a note is looked up in the named space, never on its
 * own, and an image must be cited by the note that asks for it. Asking for
 * another space's note, even a published one, answers like a note that does
 * not exist.
 *
 * **All refusals look alike**: unknown space, unpublished, removed, module
 * switched off, missing note. Telling them apart would tell a stranger which
 * of their attempts hit something.
 *
 * Not indexed by default: the page tells the engines so, in the HTTP header
 * as in the page, as long as the space does not ask for it.
 */
#[Route('/p/{slug}', name: 'notes_public', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
final class PublicSpaceController extends AbstractController
{
    public function __construct(
        private readonly NoteSpaceRepository $spaceRepository,
        private readonly MarkdownNoteRepository $noteRepository,
        private readonly MarkdownNotesViewBuilder $viewBuilder,
        private readonly MarkdownNoteImageService $imageService,
        private readonly StoredFileResponder $storedFileResponder,
        private readonly NotesContext $context,
    ) {}

    /** The space's entrance: its first note, in tree order. */
    #[Route('', name: '_space', methods: [HttpMethodEnum::Get->value])]
    public function space(string $slug): Response
    {
        $space = $this->published($slug);
        if (!$space instanceof NoteSpaceInterface) {
            return $this->unavailable();
        }

        $first = $this->viewBuilder->firstInSpace($space);
        if (null === $first) {
            return $this->unavailable();
        }

        return $this->redirectToRoute('notes_public_note', ['slug' => $slug, 'id' => $first]);
    }

    /**
     * `__id__` passes the requirement so the page receives a single address
     * template; it arrives here as 0, which is in no space.
     */
    #[Route('/{id}', name: '_note', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function note(string $slug, int $id): Response
    {
        $space = $this->published($slug);
        $note = $space instanceof NoteSpaceInterface ? $this->noteIn($space, $id) : null;

        if (!$space instanceof NoteSpaceInterface || !$note instanceof MarkdownNoteInterface) {
            return $this->unavailable();
        }

        $response = $this->render('@Notes/public/note.html.twig', $this->viewBuilder->publicView($space, $note));

        if (!$space->isIndexable()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }

    /** An image, served only if the published note asking for it cites it. */
    #[Route('/{id}/images/{filename}', name: '_image', requirements: ['id' => '\d+', 'filename' => '[^/]+'], methods: [HttpMethodEnum::Get->value])]
    public function image(string $slug, int $id, string $filename): Response
    {
        $space = $this->published($slug);
        $note = $space instanceof NoteSpaceInterface ? $this->noteIn($space, $id) : null;

        if (!$note instanceof MarkdownNoteInterface || !in_array($filename, $this->imageService->extractFilenames($note->getContent()), true)) {
            throw $this->createNotFoundException();
        }

        $key = $this->imageService->keyOrNull($filename, $this->imageService->bucketOf($note));
        if (null === $key) {
            throw $this->createNotFoundException();
        }

        return $this->storedFileResponder->respond($key);
    }

    private function published(string $slug): ?NoteSpaceInterface
    {
        if (!$this->context->isMarkdownEnabled()) {
            return null;
        }

        return $this->spaceRepository->findPublishedBySlug($slug);
    }

    private function noteIn(NoteSpaceInterface $space, int $id): ?MarkdownNoteInterface
    {
        $note = $this->noteRepository->findOneLiving($id);

        return $note instanceof MarkdownNoteInterface && $note->getSpace()->getId() === $space->getId() ? $note : null;
    }

    private function unavailable(): Response
    {
        return $this->render('@Notes/share/unavailable.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
