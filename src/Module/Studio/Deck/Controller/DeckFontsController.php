<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Studio\Deck\Service\DeckFonts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function flush;

/**
 * The fonts a free slide may be set in, uploaded and served.
 *
 * **Served here and not through `/uploads`**, for the reason `DeckFonts`
 * gives: a page may load fonts from its own origin only, and a library kept on
 * object storage answers `/uploads` with a redirect elsewhere. This answers
 * with the bytes, whatever the storage, so a share link draws its words in the
 * face they were set in.
 *
 * Anonymous on purpose: a share link is read by somebody without an account,
 * and a font is not a secret. What it does not do is serve anything that is
 * not a font, whatever id it is asked for.
 */
final class DeckFontsController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly DeckFonts $fonts,
        private readonly DocumentRepository $documents,
        private readonly StorageManager $storage,
        private readonly InlineImageUploader $uploader,
        private readonly UploadPolicyProvider $uploadPolicies,
    ) {}

    #[Route('/decks/fonts/{id}', name: 'public_deck_font', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function serve(int $id): Response
    {
        $document = $this->documents->find($id);

        if (!$this->fonts->isFontDocument($document) || $document->isTrashed() || null === $document->getFilePath()) {
            throw new NotFoundHttpException();
        }

        $adapter = $this->storage->forDisk($document->getStorageDisk());
        $path = $document->getFilePath();

        $response = new StreamedResponse(static function () use ($adapter, $path): void {
            foreach ($adapter->readStream($path) as $chunk) {
                echo $chunk;
                flush();
            }
        });

        // A font file never changes under its id: replacing it in the library
        // writes a new file. A year, and the browser keeps it across decks.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('Content-Type', $this->fonts->contentTypeOf($document));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }

    /**
     * The uploaded fonts, for the picker.
     */
    #[Route('/backend/studio/decks/fonts', name: 'backend_studio_deck_fonts', methods: [HttpMethodEnum::Get->value], priority: 10)]
    #[IsGranted('studio.decks.view')]
    public function list(): JsonResponse
    {
        return $this->jsonSuccess(['fonts' => $this->fonts->all()]);
    }

    /**
     * A font file, filed in the library and offered at once.
     *
     * Two privileges: editing decks, which is what the person is doing, and
     * creating documents, since the file becomes one. The library's own upload
     * asks for the second, and a font must not be a way round it.
     */
    #[Route('/backend/studio/decks/fonts/upload', name: 'backend_studio_deck_font_upload', methods: [HttpMethodEnum::Post->value], priority: 10)]
    #[IsGranted('studio.decks.edit')]
    #[IsGranted('ged.documents.create')]
    public function upload(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (null === $file) {
            return $this->jsonFailure('backend.studio.decks.free.font_errors.required');
        }

        if (!$this->fonts->isFontFile($file, $file->getClientOriginalName())) {
            return $this->jsonFailure('backend.studio.decks.free.font_errors.not_a_font');
        }

        if ($this->uploadPolicies->forStaffDocuments()->refusalFor($file) instanceof UploadRefusalEnum) {
            return $this->jsonFailure('backend.studio.decks.free.font_errors.refused');
        }

        return $this->jsonSuccess(['font' => $this->fonts->describe($this->uploader->upload($file))]);
    }
}
