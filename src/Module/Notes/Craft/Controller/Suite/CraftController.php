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
 * Importer un document Craft dans un espace de notes, et l'y remettre à jour.
 *
 * **Les règles sont celles d'une note ordinaire.** Importer, c'est créer une
 * note : il faut pouvoir écrire dans l'espace (et dans le dossier) où elle
 * arrive. Rafraîchir, c'est la réécrire : il faut pouvoir écrire la note. Un
 * espace ou une note hors de portée répond 404, comme partout dans le module.
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
     * Ce que la connexion Craft laisse voir.
     *
     * Une liste courte, et c'est voulu : la connexion ne porte que les
     * documents désignés dans Craft, ce qui est la seule façon d'éviter qu'un
     * jeton posé sur un serveur loué ouvre tout un savoir personnel.
     *
     * `configured` plutôt qu'une liste vide muette : un écran qui ne propose
     * rien doit pouvoir dire si c'est parce que l'intégration est éteinte ou
     * parce que la connexion est vide.
     */
    #[Route('/documents', name: '_documents', methods: [HttpMethodEnum::Get->value])]
    public function documents(): JsonResponse
    {
        $documents = $this->craft->documents();

        return $this->jsonSuccess([
            'configured' => $this->craft->isConfigured(),
            // Trois états, pas deux : éteinte, injoignable, et ouverte mais
            // vide. Chacun se répare à un endroit différent.
            'reachable' => null !== $documents,
            'documents' => $documents ?? [],
        ]);
    }

    /**
     * Un document Craft, devenu une note de l'espace demandé.
     *
     * Le titre vient de la liste et non du corps : c'est celui que Craft
     * affiche, et le Markdown rendu commence rarement par lui.
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

        // Le dossier impose son espace ; sans dossier, l'espace demandé, et
        // son espace personnel à défaut - la même règle qu'une création.
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
     * La note, remise sur la version actuelle de son document Craft.
     *
     * **Elle remplace.** L'écran demande confirmation avant, et l'état
     * remplacé entre dans l'historique de la note, d'où on le fait revenir :
     * une note est une copie, la rafraîchir refait la copie.
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

        // Gardée quoi qu'il arrive, et non selon l'écart habituel entre deux
        // versions : ce qui est remplacé ici vient d'une main, pas d'un
        // enregistrement automatique.
        $this->history->keep($note, $user);

        if (!$this->importer->refresh($note)) {
            return $this->jsonFailure('notes.craft.errors.unreachable');
        }

        return $this->jsonSuccess([
            'note' => $this->serializer->withFavorites($this->favorites->mapFor($user)['notes'])->serializeDetail($note),
        ]);
    }
}
