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
 * Un espace publié, lu sans compte.
 *
 * **Tout part de l'adresse de l'espace**, et rien dans la requête n'élargit
 * ce qu'elle ouvre : une note se cherche dans l'espace nommé, jamais seule,
 * et une image doit être citée par la note qui la demande. Demander la note
 * d'un autre espace, même publié, répond comme une note qui n'existe pas.
 *
 * **Tous les refus se ressemblent** : espace inconnu, dépublié, retiré,
 * module éteint, note absente. Les distinguer dirait à un inconnu lesquelles
 * de ses tentatives ont touché quelque chose.
 *
 * Pas indexé par défaut : la page le dit aux moteurs, dans l'en-tête HTTP
 * comme dans la page, tant que l'espace ne le demande pas.
 */
#[Route('/p/{slug}', name: 'notes_public', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
final class PublicSpaceController extends AbstractController
{
    public function __construct(
        private readonly NoteSpaceRepository $spaces,
        private readonly MarkdownNoteRepository $notes,
        private readonly MarkdownNotesViewBuilder $viewBuilder,
        private readonly MarkdownNoteImageService $images,
        private readonly StoredFileResponder $storedFileResponder,
        private readonly NotesContext $context,
    ) {}

    /** L'entrée de l'espace : sa première note, dans l'ordre de l'arbre. */
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
     * `__id__` passe l'exigence pour que la page reçoive un seul modèle
     * d'adresse ; il arrive ici comme 0, qui n'est dans aucun espace.
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

    /** Une image, servie seulement si la note publiée qui la demande la cite. */
    #[Route('/{id}/images/{filename}', name: '_image', requirements: ['id' => '\d+', 'filename' => '[^/]+'], methods: [HttpMethodEnum::Get->value])]
    public function image(string $slug, int $id, string $filename): Response
    {
        $space = $this->published($slug);
        $note = $space instanceof NoteSpaceInterface ? $this->noteIn($space, $id) : null;

        if (!$note instanceof MarkdownNoteInterface || !in_array($filename, $this->images->extractFilenames($note->getContent()), true)) {
            throw $this->createNotFoundException();
        }

        $key = $this->images->keyOrNull($filename, $this->images->bucketOf($note));
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

        return $this->spaces->findPublishedBySlug($slug);
    }

    private function noteIn(NoteSpaceInterface $space, int $id): ?MarkdownNoteInterface
    {
        $note = $this->notes->findOneLiving($id);

        return $note instanceof MarkdownNoteInterface && $note->getSpace()->getId() === $space->getId() ? $note : null;
    }

    private function unavailable(): Response
    {
        return $this->render('@Notes/share/unavailable.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
