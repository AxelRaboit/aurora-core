<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\BinaryFileServer;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly BinaryFileServer $binaryFileServer,
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
     * Serve an image to its owner. Per-user auth is enforced by routing
     * the path through `MarkdownNoteImageService::path()`, which builds the
     * absolute path *from the current user's directory* and refuses any
     * filename that resolves outside it (path traversal guard). A 404 is
     * returned for missing or non-owned images.
     *
     * The `filename` requirement bans `/` to keep the route confined to
     * a flat filename - no nested traversal can ever reach the action.
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

        try {
            $path = $this->imageService->path($filename, $user);
        } catch (RuntimeException) {
            return $this->jsonNotFound();
        }

        try {
            // Through the shared server rather than a `BinaryFileResponse` of
            // its own: that is where `nosniff` is set and where the types a
            // browser would run as a document are handed over as downloads.
            // Building the response here meant this route quietly opted out of
            // both. The default it applies - private, one hour - is the one
            // this route wants anyway: filenames are uuids, so a different
            // file is a different URL, and the content is auth-gated.
            return $this->binaryFileServer->serve($path, $this->imageService->root());
        } catch (RuntimeException) {
            return $this->jsonNotFound();
        }
    }
}
