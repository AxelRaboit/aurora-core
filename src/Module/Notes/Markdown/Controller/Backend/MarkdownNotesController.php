<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteReorderInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Serializer\MarkdownNoteSerializerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteArchive;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImporter;
use Aurora\Module\Notes\Markdown\View\MarkdownNotesViewBuilder;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_filter;
use function array_values;
use function date;
use function iconv;
use function is_array;
use function is_numeric;
use function preg_replace;
use function sprintf;

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
        private readonly NoteFolderRepository $folders,
        private readonly MarkdownNoteArchive $archive,
        private readonly MarkdownNoteImporter $importer,
        private readonly UploadPolicyProvider $uploadPolicies,
    ) {}

    /**
     * Tous les documents : le carnet, à sa racine.
     *
     * L'adresse rendait la première note, faute de page à montrer. Le carnet
     * a maintenant la sienne, et c'est elle qui accueille : ce qu'on cherche
     * en arrivant est le plus souvent une note qu'on n'a pas sous les yeux,
     * pas celle qu'on a ouverte en dernier.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->render('@Notes/backend/markdown/index.html.twig', $this->viewBuilder->indexView($user));
    }

    /**
     * Le contenu d'un dossier, à son adresse.
     *
     * Une adresse par dossier, comme une adresse par note : elle se
     * transmet, le clic du milieu ouvre un onglet, et le fil d'Ariane est
     * calculé côté serveur pour que le rechargement n'affiche pas la racine
     * une fraction de seconde.
     */
    #[Route('/folder/{id}', name: '_folder', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function folder(int $id): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $folder = $this->folders->findOneByUserAndId($user, $id);

        if (!$folder instanceof NoteFolderInterface || $folder->isTrashed()) {
            throw $this->createNotFoundException();
        }

        return $this->render(
            '@Notes/backend/markdown/index.html.twig',
            $this->viewBuilder->indexView($user, folder: $folder),
        );
    }

    /**
     * Toutes les notes de la personne, à plat, sans leur texte.
     *
     * Avec leur premier paragraphe quand même : c'est ce que la vue en
     * mosaïque montre sur une carte, et le faire ici évite une requête par
     * carte. Le reste du corps ne quitte pas le serveur tant qu'une note
     * n'est pas ouverte.
     */
    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $excerpts = $this->repository->findExcerptsForUser($user);

        return $this->jsonSuccess([
            'notes' => array_map(
                static fn (array $note): array => [...$note, 'excerpt' => $excerpts[(int) $note['id']] ?? null],
                $this->repository->findFlatListForUser($user),
            ),
        ]);
    }

    #[Route('/{id}/restore', name: '_restore', methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->restore($note);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/force-delete', name: '_force_delete', methods: [HttpMethodEnum::Post->value])]
    public function forceDelete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->forceDelete($note);

        return $this->jsonSuccess();
    }

    /**
     * Destroys every note this user has in the trash, sub-pages included.
     *
     * Only theirs: a note belongs to its author, and there is no view in which
     * emptying one person's trash should reach another's.
     */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $deleted = 0;
        foreach ($this->repository->findTrashedRootsForUser($user) as $note) {
            $this->manager->forceDelete($note);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    /**
     * Le carnet entier, en Markdown, dans un zip.
     *
     * **La porte de sortie.** Un carnet en base est un enfermement tant qu'on
     * ne peut pas le reprendre : ceci rend des fichiers `.md` qu'un éditeur de
     * texte ouvre et qu'Obsidian lit, dans l'arborescence des notes, avec les
     * étiquettes en préambule. Rien n'y est propre à Aurora.
     *
     * Le fichier temporaire est supprimé après l'envoi : `deleteFileAfterSend`
     * le fait une fois la réponse écrite, pas avant, sinon un gros carnet part
     * dans le vide.
     */
    #[Route('/export', name: '_export', methods: [HttpMethodEnum::Get->value])]
    public function export(): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $path = $this->archive->zipFor($user);

        return $this->file($path, sprintf('notes-%s.zip', date('Y-m-d')))->deleteFileAfterSend(true);
    }

    /** Une note seule, pour l'emporter sans emporter le reste. */
    // `__id__` accepté comme sur `_show` : la vue reçoit un gabarit d'adresse
    // dans lequel elle substitue l'identifiant, donc le générateur doit savoir
    // produire l'adresse avec le marqueur dedans.
    #[Route('/{id}/export', name: '_export_one', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function exportOne(int $id): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);

        if (!$note instanceof MarkdownNoteInterface) {
            throw $this->createNotFoundException();
        }

        $response = new Response($this->archive->fileFor($note));
        $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        // Un repli ASCII est obligatoire : le titre d'une note est écrit par
        // quelqu'un, donc il a des accents, et `makeDisposition` refuse d'en
        // deviner un tout seul. Le nom accentué reste, dans le paramètre que
        // les navigateurs lisent depuis quinze ans.
        $name = $this->archive->nameOf($note).'.md';

        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $name,
                $this->asciiName($name),
            ),
        );

        return $response;
    }

    /**
     * Des fichiers Markdown, ou un zip, remis en notes.
     *
     * Dans le dossier nommé, ou à la racine. Rien n'est écrasé : une note du
     * même nom donne une seconde note, parce que fusionner demanderait de
     * décider ce qui gagne et que personne ne l'a demandé ici.
     */
    #[Route('/import', name: '_import', methods: [HttpMethodEnum::Post->value])]
    public function import(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $files = $request->files->all()['files'] ?? [];
        $files = is_array($files) ? $files : [$files];
        $files = array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));

        if ([] === $files) {
            return $this->jsonInvalidInput(['files' => 'notes.markdown.import.errors.required']);
        }

        $folderId = $request->request->get('folderId');
        $folder = null;

        if (is_numeric($folderId)) {
            $folder = $this->folders->findOneByUserAndId($user, (int) $folderId);

            if (!$folder instanceof NoteFolderInterface) {
                return $this->jsonNotFound();
            }
        }

        $created = 0;

        foreach ($files as $file) {
            $refusal = $this->uploadPolicies->forStaffDocuments()->refusalFor($file);

            if ($refusal instanceof UploadRefusalEnum) {
                return $this->jsonInvalidInput(['files' => match ($refusal) {
                    UploadRefusalEnum::TooLarge => 'backend.ged.documents.errors.upload_too_large',
                    UploadRefusalEnum::TypeRefused => 'backend.ged.documents.errors.upload_type_refused',
                    UploadRefusalEnum::Broken => 'backend.ged.documents.errors.upload_failed',
                }]);
            }

            $created += $this->importer->import($user, $file, $folder);
        }

        return $this->jsonSuccess(['created' => $created]);
    }

    /**
     * Le même nom, réduit à ce qu'un vieux client sait lire.
     *
     * Translittéré plutôt que tronqué : « Séance en extérieur » devient
     * « Seance en exterieur » et reste reconnaissable, là où un filtre brutal
     * rendrait « S ance en ext rieur ».
     */
    private function asciiName(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        if (false === $ascii) {
            $ascii = $name;
        }

        return (string) preg_replace('/[^\x20-\x7E]+/', '-', $ascii);
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
        $raw = $data['folderId'] ?? null;

        $folder = null;
        if (null !== $raw && '' !== $raw) {
            $folder = $this->folders->findOneByUserAndId($user, (int) $raw);
            if (!$folder instanceof NoteFolderInterface) {
                return $this->jsonNotFound();
            }
        }

        $this->manager->move($note, $folder);

        return $this->jsonSuccess(['note' => $this->serializer->serializeListItem($note)]);
    }

    /** Épingler une note au menu, ou l'en décrocher. */
    #[Route('/{id}/favorite', name: '_favorite', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function favorite(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->repository->findOneByUserAndId($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['favorite' => $this->manager->toggleFavorite($note)]);
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

        $this->manager->reorder($user, $input->entries);

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

            // Le dossier de la note voyage avec elle : le fil d'Ariane le
            // montre, et le retour à la bibliothèque rend l'endroit où la
            // note est rangée plutôt que la racine.
            return $this->render(
                '@Notes/backend/markdown/index.html.twig',
                $this->viewBuilder->indexView($user, $id, $note->getFolder()),
            );
        }

        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }
}
