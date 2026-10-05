<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Pexels\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Ged\Pexels\Service\PexelsClient;
use Aurora\Module\Ged\Pexels\Service\PexelsImporter;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The picker's window onto Pexels.
 *
 * Two endpoints, both proxies: searching so the API key stays server-side,
 * importing so the stored URL is checked before any page renders it. Neither
 * accepts anything the browser cannot already see.
 */
#[Route('/suite/ged/pexels', name: 'suite_ged_pexels')]
#[IsGranted('ged.documents.view')]
final class PexelsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PexelsClient $client,
        private readonly PexelsImporter $importer,
        private readonly DocumentSerializerInterface $serializer,
    ) {}

    #[Route('/search', name: '_search', methods: [HttpMethodEnum::Get->value])]
    public function search(Request $request): JsonResponse
    {
        // Reported rather than failed: the picker draws a "not configured"
        // notice, which tells an administrator what to do. A 500 would only
        // tell them something broke.
        if (!$this->client->isConfigured()) {
            return $this->jsonSuccess(['configured' => false, 'results' => [], 'totalPages' => 0]);
        }

        $result = $this->client->search(
            (string) $request->query->get('q', ''),
            $request->query->getInt('page', 1),
        );

        return $this->jsonSuccess([
            'configured' => true,
            'results' => $result['results'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * Files the chosen photo and hands back an ordinary serialized document,
     * so the picker can resolve with it exactly as it does for a library
     * pick - the field on the other side never learns where it came from.
     */
    #[Route('/import', name: '_import', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.create')]
    public function import(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) ($this->decodeJson($request)['photo'] ?? []);

        try {
            $document = $this->importer->import($payload);
        } catch (InvalidArgumentException) {
            return $this->jsonFailure('suite.ged.pexels.errors.invalid_photo');
        } catch (RuntimeException) {
            // The photo is real but the fetch failed - a timeout, a CDN
            // hiccup. Told apart from a refused payload because the answer is
            // different: try again rather than pick another photo.
            return $this->jsonFailure('suite.ged.pexels.errors.download_failed');
        }

        return $this->jsonSuccess(['document' => $this->serializer->serialize($document)]);
    }
}
