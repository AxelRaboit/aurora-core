<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_numeric;
use function str_contains;

#[Route('/backend/notes/markdown/images', name: 'backend_notes_markdown_images')]
#[IsGranted('notes.markdown.use')]
final class MarkdownNotesImagesController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly MarkdownNoteImageService $imageService,
        private readonly StoredFileResponder $storedFileResponder,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly NoteSpaceAccess $spaceAccess,
        private readonly NoteSpaceRepository $spaces,
    ) {}

    /**
     * Une image d'une note qu'on lit sans en être l'auteur.
     *
     * Les images sont rangées par propriétaire, et la route ordinaire
     * construit sa clé avec la personne connectée : une note partagée lue par
     * quelqu'un d'autre s'affichait donc sans ses images. Ici la clé est celle
     * de l'auteur, et c'est la règle de lecture de la note - la sienne,
     * partagée, ou rangée dans un dossier partagé - qui décide, la même que
     * pour le texte.
     */
    #[Route(
        '/of/{noteId}/{filename}',
        name: '_read',
        requirements: ['noteId' => '\d+', 'filename' => '[A-Za-z0-9._-]+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function read(int $noteId, string $filename): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Lisible, et citée par cette note : lire une note partagée ne doit
        // pas ouvrir les images des notes privées de son auteur, même à qui
        // en connaîtrait le nom.
        $note = $this->spaceAccess->readableNote($user, $noteId);
        if (!$note instanceof MarkdownNoteInterface || !str_contains((string) $note->getContent(), $filename)) {
            return $this->jsonNotFound();
        }

        $key = $this->imageService->keyOrNull($filename, $this->imageService->bucketOf($note));
        if (null === $key) {
            return $this->jsonNotFound();
        }

        try {
            return $this->storedFileResponder->respond($key);
        } catch (NotFoundHttpException) {
            return $this->jsonNotFound();
        }
    }

    /**
     * Accepts a single file via the `image` multipart field. Returns the
     * stored filename + the serve URL the Vue editor can splice into the
     * markdown as `![alt](url)`.
     */
    #[Route('/upload', name: '_upload', methods: [HttpMethodEnum::Post->value])]
    public function upload(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            return $this->jsonInvalidInput(['image' => 'Missing or invalid upload.']);
        }

        // Une image rejoint le compartiment de l'espace de sa note, pour que
        // tous ses lecteurs la voient : il faut pouvoir écrire dans cet
        // espace. Sans espace dit, c'est son espace personnel.
        $raw = $request->request->get('spaceId') ?? $request->query->get('spaceId');
        $space = is_numeric($raw) ? $this->spaceAccess->writableSpace($user, (int) $raw) : $this->spaceAccess->personalSpace($user);
        if (!$space instanceof NoteSpaceInterface) {
            return $this->jsonNotFound();
        }

        try {
            $filename = $this->imageService->store($file, $space);
        } catch (FileException $fileException) {
            return $this->jsonInvalidInput(['image' => $fileException->getMessage()]);
        }

        return $this->jsonSuccess([
            'filename' => $filename,
            'url' => $this->urlGenerator->generate('backend_notes_markdown_images_serve', ['filename' => $filename]),
        ]);
    }

    /**
     * Sert une image à qui la possède.
     *
     * La règle d'accès tient dans la clé : elle est construite avec
     * l'identifiant de **la personne connectée**, jamais avec celui que porte
     * la demande. Réclamer l'image d'un autre revient donc à réclamer une clé
     * qui n'existe pas, et la réponse est le même 404 que pour une image
     * supprimée. Rien ne dit à qui demande si le fichier existe ailleurs.
     *
     * C'est plus court que ce qu'il y avait : un chemin absolu construit
     * depuis le dossier de la personne, un `realpath`, et une comparaison à
     * la racine pour empêcher une remontée. Une clé d'objet n'a pas de
     * dossier parent.
     */
    #[Route(
        '/{filename}',
        name: '_serve',
        requirements: ['filename' => '[A-Za-z0-9._-]+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(string $filename): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Les espaces qu'on peut lire, le sien d'abord - la liste le met en
        // tête : l'adresse d'une image ne porte que son nom, et une image d'un
        // espace partagé a la même adresse chez tous ses lecteurs.
        $this->spaceAccess->personalSpace($user);
        foreach ($this->spaces->findReadableFor($user) as $bucket) {
            $key = $this->imageService->keyOrNull($filename, $bucket);

            if (null === $key) {
                return $this->jsonNotFound();
            }

            try {
                // Par le répondeur partagé plutôt qu'une réponse fabriquée ici :
                // c'est lui qui pose `nosniff`, qui rend en téléchargement ce
                // qu'un navigateur exécuterait comme un document, et qui sait
                // servir un fichier local tel quel et diffuser un distant par
                // morceaux.
                return $this->storedFileResponder->respond($key);
            } catch (NotFoundHttpException) {
                continue;
            }
        }

        return $this->jsonNotFound();
    }
}
