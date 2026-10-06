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
 * Les polices déposées pour les diapositives, servies à qui lit une
 * présentation.
 *
 * **Servies ici et pas par `/uploads`**, pour la raison que donne
 * {@see DeckFonts} : une page ne charge ses polices que depuis sa propre
 * origine, et une médiathèque rangée sur un stockage objet répond à
 * `/uploads` par une redirection vers un autre domaine. Ici, ce sont les
 * octets, quel que soit le stockage : un lien de lecture dessine ses mots
 * dans la police où ils ont été composés.
 *
 * Sans compte exprès : un lien de lecture se lit sans compte, et une police
 * n'est pas un secret. Ce qui n'est pas une police n'est jamais servi, quel
 * que soit l'identifiant demandé.
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

        // Un fichier de police ne change jamais sous son identifiant : le
        // remplacer dans la médiathèque écrit un autre fichier. Un an, et le
        // navigateur le garde d'une présentation à l'autre.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('Content-Type', $this->fonts->contentTypeOf($document));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}
