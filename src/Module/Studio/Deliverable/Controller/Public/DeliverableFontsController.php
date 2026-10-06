<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckFonts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function flush;

/**
 * The fonts uploaded for slides, served to whoever reads a presentation.
 *
 * **Served here and not through `/uploads`**, for the reason given in
 * {@see DeckFonts}: a page only loads its fonts from its own origin, and a
 * media library kept on object storage answers `/uploads` with a redirect to
 * another domain. Here, it is the bytes, whatever the storage: a reading
 * link draws its words in the font they were set in.
 *
 * No account on purpose: a reading link is read without an account, and a
 * font is not a secret. What is not a font is never served, whatever id is
 * requested.
 *
 * Named `public_deliverable_font`, it goes dark when neither the
 * Deliverables module nor the client spaces, which hold the presentations,
 * are switched on, see `StudioRouteGateSubscriber`.
 */
final class DeliverableFontsController extends AbstractController
{
    public function __construct(
        private readonly DeckFonts $fonts,
        private readonly DocumentRepository $documents,
        private readonly StorageManager $storage,
    ) {}

    #[Route('/deliverables/fonts/{id}', name: 'public_deliverable_font', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
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

        // A font file never changes under its id: replacing it in the media
        // library writes another file. One year, and the browser keeps it
        // from one presentation to the next.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('Content-Type', $this->fonts->contentTypeOf($document));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}
