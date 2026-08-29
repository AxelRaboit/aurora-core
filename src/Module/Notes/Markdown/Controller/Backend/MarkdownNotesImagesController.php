<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

        $response = new BinaryFileResponse($path);
        // Browser cache is fine: filenames are uuid-based so a different
        // file is a different URL. Mark private so shared caches don't
        // pick it up (image is auth-gated content).
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }
}
