<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
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

#[Route('/backend/notes/markdown/images', name: 'backend_notes_markdown_images')]
#[IsGranted('notes.markdown.use')]
final class MarkdownNotesImagesController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly MarkdownNoteImageService $imageService,
        private readonly StoredFileResponder $storedFileResponder,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

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

        try {
            $filename = $this->imageService->store($file, $user);
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

        $key = $this->imageService->keyOrNull($filename, $user);

        if (null === $key) {
            return $this->jsonNotFound();
        }

        try {
            // Par le répondeur partagé plutôt qu'une réponse fabriquée ici :
            // c'est lui qui pose `nosniff`, qui rend en téléchargement ce
            // qu'un navigateur exécuterait comme un document, et qui sait
            // servir un fichier local tel quel et diffuser un distant par
            // morceaux. Sa politique - privé, une heure - est celle que cette
            // route veut : les noms sont des uuid, donc un autre fichier est
            // une autre adresse, et le contenu est derrière une autorisation.
            return $this->storedFileResponder->respond($key);
        } catch (NotFoundHttpException) {
            return $this->jsonNotFound();
        }
    }
}
