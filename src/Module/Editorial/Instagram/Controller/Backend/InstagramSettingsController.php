<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Instagram\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Instagram\Setting\InstagramSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function mb_trim;

#[Route('/backend/editorial/instagram/settings', name: 'backend_editorial_instagram_settings')]
#[IsGranted('configuration.settings.manage')]
final class InstagramSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly InstagramSettings $settings,
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
        $accessToken = array_key_exists('accessToken', $payload) ? mb_trim((string) $payload['accessToken']) : null;
        $businessAccountId = array_key_exists('businessAccountId', $payload) ? mb_trim((string) $payload['businessAccountId']) : null;

        if ($enabled && !$termsAccepted) {
            return $this->jsonFailure('backend.editorial.instagram.errors.terms_required');
        }

        $tokenAfterSave = $accessToken ?? $this->settings->accessToken();
        $idAfterSave = $businessAccountId ?? $this->settings->businessAccountId();

        if ($enabled && ('' === $tokenAfterSave || '' === $idAfterSave)) {
            return $this->jsonFailure('backend.editorial.instagram.errors.account_required');
        }

        $this->settings->save(
            enabled: $enabled,
            termsAccepted: $termsAccepted,
            accessToken: $accessToken,
            businessAccountId: $businessAccountId,
            acceptedBy: (string) $this->getUser()?->getUserIdentifier(),
        );

        return $this->jsonSuccess($this->settings->state());
    }
}
