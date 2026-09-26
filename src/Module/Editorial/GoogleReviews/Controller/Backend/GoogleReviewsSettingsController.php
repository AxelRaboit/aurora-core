<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GoogleReviews\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\GoogleReviews\Setting\GoogleReviewsSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function mb_trim;

#[Route('/backend/editorial/google-reviews/settings', name: 'backend_editorial_google_reviews_settings')]
#[IsGranted('configuration.settings.manage')]
final class GoogleReviewsSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly GoogleReviewsSettings $settings,
    ) {}

    #[Route('', name: '_show', methods: [HttpMethodEnum::Get->value])]
    public function show(): JsonResponse
    {
        return $this->jsonSuccess($this->settings->state());
    }

    #[Route('', name: '_save', methods: [HttpMethodEnum::Post->value])]
    public function save(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $enabled = true === ($payload['enabled'] ?? false);
        $termsAccepted = true === ($payload['termsAccepted'] ?? false);
        $apiKey = array_key_exists('apiKey', $payload) ? mb_trim((string) $payload['apiKey']) : null;
        $placeId = array_key_exists('placeId', $payload) ? mb_trim((string) $payload['placeId']) : null;

        if ($enabled && !$termsAccepted) {
            return $this->jsonFailure('backend.editorial.google_reviews.errors.terms_required');
        }

        $keyAfterSave = $apiKey ?? $this->settings->apiKey();
        $placeAfterSave = $placeId ?? $this->settings->placeId();

        if ($enabled && ('' === $keyAfterSave || '' === $placeAfterSave)) {
            return $this->jsonFailure('backend.editorial.google_reviews.errors.place_required');
        }

        $this->settings->save(
            enabled: $enabled,
            termsAccepted: $termsAccepted,
            apiKey: $apiKey,
            placeId: $placeId,
            acceptedBy: (string) $this->getUser()?->getUserIdentifier(),
        );

        return $this->jsonSuccess($this->settings->state());
    }
}
