<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Suite;

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

#[Route('/suite/notes/markdown/images', name: 'suite_notes_markdown_images')]
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
     * An image of a note you read without being its author.
     *
     * Images are stored per owner, and the ordinary route builds its key with
     * the logged-in person: a shared note read by someone else was therefore
     * displayed without its images. Here the key is the author's, and it is
     * the note's read rule - your own, shared, or filed in a shared folder -
     * that decides, the same as for the text.
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

        // Readable, and cited by this note: reading a shared note must not
        // open the images of its author's private notes, even to someone
        // who knew their name.
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

        // An image joins the bucket of its note's space, so that all its
        // readers see it: you must be able to write in that space. With no
        // space given, it is your personal space.
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
            'url' => $this->urlGenerator->generate('suite_notes_markdown_images_serve', ['filename' => $filename]),
        ]);
    }

    /**
     * Serves an image to whoever owns it.
     *
     * The access rule lies in the key: it is built with the id of **the
     * logged-in person**, never with the one the request carries. Asking for
     * someone else's image therefore amounts to asking for a key that does
     * not exist, and the answer is the same 404 as for a deleted image.
     * Nothing tells the requester whether the file exists elsewhere.
     *
     * It is shorter than what was there: an absolute path built from the
     * person's folder, a `realpath`, and a comparison with the root to
     * prevent climbing up. An object key has no parent folder.
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

        // The spaces you can read, your own first - the list puts it at the
        // top: an image's address only carries its name, and an image of a
        // shared space has the same address for all its readers.
        $this->spaceAccess->personalSpace($user);
        foreach ($this->spaces->findReadableFor($user) as $bucket) {
            $key = $this->imageService->keyOrNull($filename, $bucket);

            if (null === $key) {
                return $this->jsonNotFound();
            }

            try {
                // Through the shared responder rather than a response built
                // here: it is the one that sets `nosniff`, that turns into a
                // download what a browser would run as a document, and that
                // knows how to serve a local file as is and stream a remote
                // one in chunks.
                return $this->storedFileResponder->respond($key);
            } catch (NotFoundHttpException) {
                continue;
            }
        }

        return $this->jsonNotFound();
    }
}
