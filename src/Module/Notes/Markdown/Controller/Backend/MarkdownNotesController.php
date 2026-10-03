<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Configuration\Setting\Service\SiteDateFormatter;
use Aurora\Module\Ged\Pexels\Service\PexelsClient;
use Aurora\Module\Notes\Favorite\Manager\NoteFavoriteManagerInterface;
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
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly NoteSpaceAccess $spaceAccess,
        private readonly NoteFavoriteManagerInterface $favorites,
        private readonly TranslatorInterface $translator,
        private readonly SiteDateFormatter $dates,
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

        // Un dossier d'un espace qu'on peut lire.
        $folder = $this->spaceAccess->readableFolder($user, $id);

        if (!$folder instanceof NoteFolderInterface) {
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

    /**
     * La note, seule, sans le back-office autour.
     *
     * Une adresse à part plutôt qu'un mode d'affichage : on la garde ouverte
     * dans un onglet, on la partage à soi-même, et le navigateur y revient.
     * C'est le gabarit du partage public qui la dessine - il est déjà fait
     * pour rendre une note sans menu ni fil d'Ariane - mais lue par son
     * propriétaire : les images passent par la route ordinaire, et un
     * wiki-lien mène à cette même vue plutôt que d'être neutralisé, puisque
     * tout le carnet est à portée.
     */
    // `__id__` est admis pour que la page reçoive un gabarit d'adresse à
    // remplir plutôt qu'une adresse par note : il arrive ici en zéro, qui
    // n'appartient à personne, donc il répond 404 comme n'importe quel
    // identifiant inconnu.
    /**
     * Entrer dans le lecteur sans choisir de note : par un favori du
     * navigateur ou par le raccourci. On arrive sur la première note du
     * carnet ; un carnet vide renvoie à la bibliothèque, où l'on en écrit une.
     */
    #[Route('/read', name: '_read_entry', methods: [HttpMethodEnum::Get->value])]
    public function readEntry(): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $first = $this->viewBuilder->firstInReadingOrder($user);

        return $this->redirectToRoute(
            null === $first ? 'backend_notes_markdown' : 'backend_notes_markdown_read',
            null === $first ? [] : ['id' => $first],
        );
    }

    #[Route('/{id}/read', name: '_read', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function read(int $id): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Tout ce que l'espace de la note laisse lire ; écrire se décide
        // ailleurs, par le rôle.
        $note = $this->spaceAccess->readableNote($user, $id);

        if (!$note instanceof MarkdownNoteInterface) {
            throw $this->createNotFoundException();
        }

        return $this->render(
            '@Notes/backend/markdown/read.html.twig',
            $this->viewBuilder->readView($user, $note),
        );
    }

    /**
     * Des photos pour le bandeau d'une note, cherchées chez Pexels.
     *
     * Un relais et non un appel direct : la clé reste sur le serveur, comme
     * pour le sélecteur de la médiathèque. Mais ce relais-ci s'arrête là -
     * **rien n'est téléchargé, rien n'entre dans la GED**. La note ne garde
     * que l'adresse de l'image et le crédit de son auteur, et si la photo
     * disparaît un jour de chez eux, on en choisit une autre.
     *
     * Sa propre route plutôt que celle de la médiathèque : celle-là exige le
     * droit `ged.documents.view`, que quelqu'un qui prend des notes n'a pas
     * forcément, et lui demander de l'obtenir pour choisir une image
     * décorative serait un droit de trop.
     */
    #[Route('/covers/search', name: '_covers_search', methods: [HttpMethodEnum::Get->value])]
    public function searchCovers(Request $request, PexelsClient $pexels): JsonResponse
    {
        // Annoncé plutôt qu'échoué : le sélecteur affiche « non configuré »,
        // ce qui dit à un administrateur quoi faire. Un 500 ne dirait que
        // « quelque chose a cassé ».
        if (!$pexels->isConfigured()) {
            return $this->jsonSuccess(['configured' => false, 'results' => []]);
        }

        $result = $pexels->search(
            (string) $request->query->get('q', ''),
            $request->query->getInt('page', 1),
        );

        return $this->jsonSuccess([
            'configured' => true,
            'results' => $result['results'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    #[Route('/{id}/restore', name: '_restore', methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);
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

        $note = $this->spaceAccess->writableNote($user, $id);
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
        // Vider une corbeille est définitif : seulement dans les espaces qu'on
        // gère. Le rédacteur restaure, il ne détruit pas.
        foreach ($this->repository->findTrashedRootsForUser($user) as $note) {
            if (!$this->spaceAccess->canManage($user, $note->getSpace())) {
                continue;
            }

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
    public function export(Request $request): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // `spaceId` n'emporte que cet espace, s'il est lisible.
        $space = null;
        $spaceId = $request->query->get('spaceId');
        if (is_numeric($spaceId)) {
            $space = $this->spaceAccess->readableSpace($user, (int) $spaceId);

            if (!$space instanceof NoteSpaceInterface) {
                throw $this->createNotFoundException();
            }
        }

        $path = $this->archive->zipFor($user, $space);

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

        // Emporter une note, c'est la lire : l'équipe s'emporte aussi.
        $note = $this->spaceAccess->readableNote($user, $id);

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

        // Sans dossier, la racine d'un espace où l'on écrit ; la sienne à défaut.
        $space = null;
        $spaceId = $request->request->get('spaceId');
        if (!$folder instanceof NoteFolderInterface && is_numeric($spaceId)) {
            $space = $this->spaceAccess->writableSpace($user, (int) $spaceId);

            if (!$space instanceof NoteSpaceInterface) {
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

            $created += $this->importer->import($user, $file, $folder, $space);
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

        // Là où l'on crée, on doit pouvoir écrire : le dossier demandé, la
        // racine de l'espace demandé, ou son espace personnel.
        $folderId = $input->getFolderId();
        $spaceId = $input->getSpaceId();
        $allowed = match (true) {
            null !== $folderId => $this->spaceAccess->writableFolder($user, $folderId) instanceof NoteFolderInterface,
            null !== $spaceId => $this->spaceAccess->writableSpace($user, $spaceId) instanceof NoteSpaceInterface,
            default => true,
        };
        if (!$allowed) {
            return $this->jsonNotFound();
        }

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

        $note = $this->spaceAccess->writableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        // Parti d'une version dépassée : quelqu'un a écrit entre-temps, et
        // enregistrer maintenant effacerait son texte sans qu'il le sache.
        // On refuse, et c'est la personne qui choisit - recharger, ou écraser
        // en connaissance de cause. Un appel qui ne dit pas sa version passe,
        // comme avant.
        if (!$input->isForce() && null !== $input->getVersion() && $input->getVersion() !== $note->getVersion()) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value, ['conflict' => true, 'version' => $note->getVersion()]);
        }

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($note, $input);

        // L'extrait voyage avec la note enregistrée : la carte de la
        // bibliothèque suit le texte sans attendre un rechargement.
        $excerpt = $this->repository->excerptOf((string) $note->getContent());
        $serializer = $this->serializer->withFavorites($this->favorites->mapFor($user)['notes']);
        if ('' !== $excerpt) {
            $serializer = $serializer->withExcerpts([(int) $note->getId() => $excerpt]);
        }

        return $this->jsonSuccess(['note' => $serializer->serializeDetail($note)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->delete($note);

        return $this->jsonSuccess();
    }

    /**
     * Une copie de la note, juste sous elle : même dossier, même texte, même
     * apparence, nommée « Copie de … ». Il faut pouvoir lire la note et
     * écrire là où elle est rangée.
     */
    #[Route('/{id}/duplicate', name: '_duplicate', methods: [HttpMethodEnum::Post->value])]
    public function duplicate(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface || $note->isTrashed()) {
            return $this->jsonNotFound();
        }

        $title = $this->translator->trans('notes.markdown.duplicate.title', ['{title}' => (string) ($note->getTitle() ?? '')]);
        $copy = $this->manager->duplicate($user, $note, $title);

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($copy)]);
    }

    /** Faire d'une note un modèle, ou la rendre à l'ordinaire. */
    #[Route('/{id}/template', name: '_template', methods: [HttpMethodEnum::Post->value])]
    public function template(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->markTemplate($note, true === ($this->decodeJson($request)['template'] ?? false));

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    /**
     * Une note neuve depuis un modèle qu'on peut lire, rangée là où l'on peut
     * écrire : le dossier demandé, la racine de l'espace demandé, ou son
     * espace personnel. `{{date}}` y devient la date du jour.
     */
    #[Route('/from-template/{id}', name: '_from_template', methods: [HttpMethodEnum::Post->value])]
    public function fromTemplate(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $template = $this->spaceAccess->readableNote($user, $id);
        if (!$template instanceof MarkdownNoteInterface || !$template->isTemplate() || $template->isTrashed()) {
            return $this->jsonNotFound();
        }

        $data = $this->decodeJson($request);
        $folder = null;
        $space = null;
        if (isset($data['folderId']) && is_numeric($data['folderId'])) {
            $folder = $this->spaceAccess->writableFolder($user, (int) $data['folderId']);
            if (!$folder instanceof NoteFolderInterface || $folder->isTrashed()) {
                return $this->jsonNotFound();
            }
            $space = $folder->getSpace();
        } elseif (isset($data['spaceId']) && is_numeric($data['spaceId'])) {
            $space = $this->spaceAccess->writableSpace($user, (int) $data['spaceId']);
            if (!$space instanceof NoteSpaceInterface) {
                return $this->jsonNotFound();
            }
        }
        $space ??= $this->spaceAccess->personalSpace($user);

        $title = mb_trim((string) ($data['title'] ?? ''));
        $note = $this->manager->createFromTemplate(
            $user,
            $template,
            $folder,
            $space,
            '' !== $title ? $title : (string) ($template->getTitle() ?? ''),
            ['{{date}}' => $this->dates->date(new DateTimeImmutable())],
        );

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    #[Route('/{id}/move', name: '_move', methods: [HttpMethodEnum::Post->value])]
    public function move(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $data = $this->decodeJson($request);
        $raw = $data['folderId'] ?? null;

        // Un dossier où l'on peut écrire, ou la racine d'un espace où l'on
        // peut écrire : `spaceId` dit laquelle, et vaut par défaut celle où
        // la note est déjà.
        $folder = null;
        $space = $note->getSpace();
        if (null !== $raw && '' !== $raw) {
            $folder = $this->spaceAccess->writableFolder($user, (int) $raw);
            if (!$folder instanceof NoteFolderInterface || $folder->isTrashed()) {
                return $this->jsonNotFound();
            }
        } elseif (isset($data['spaceId']) && is_numeric($data['spaceId'])) {
            $space = $this->spaceAccess->writableSpace($user, (int) $data['spaceId']);
            if (!$space instanceof NoteSpaceInterface) {
                return $this->jsonNotFound();
            }
        }

        $this->manager->move($note, $folder, $space);

        return $this->jsonSuccess(['note' => $this->serializer->withFavorites($this->favorites->mapFor($user)['notes'])->serializeListItem($note)]);
    }

    /**
     * Ajouter une note à ses favoris, ou l'en retirer.
     *
     * Lire suffit : les favoris sont à la personne, pas à la note.
     */
    #[Route('/{id}/favorite', name: '_favorite', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function favorite(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['favorite' => $this->favorites->toggle($user, $note)]);
    }

    #[Route('/{id}/backlinks', name: '_backlinks', methods: [HttpMethodEnum::Get->value])]
    public function backlinks(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
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

        $note = $this->spaceAccess->readableNote($user, $id);
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

        // L'éditeur s'ouvre sur ce qu'on peut écrire. Une note qu'on peut
        // lire sans l'écrire - l'équipe sans le droit, le partage d'un
        // collègue - s'ouvre dans le lecteur, qui est fait pour ça.
        $note = $this->spaceAccess->writableNote($user, $id);
        if ($note instanceof MarkdownNoteInterface && $note->isTrashed()) {
            $note = null;
        }

        if (!$request->isXmlHttpRequest()) {
            if (!$note instanceof MarkdownNoteInterface) {
                if ($this->spaceAccess->readableNote($user, $id) instanceof MarkdownNoteInterface) {
                    return $this->redirectToRoute('backend_notes_markdown_read', ['id' => $id]);
                }

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
