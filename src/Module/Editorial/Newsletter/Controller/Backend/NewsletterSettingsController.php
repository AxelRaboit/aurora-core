<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Newsletter\Setting\NewsletterSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function mb_trim;

#[Route('/backend/editorial/newsletter/settings', name: 'backend_editorial_newsletter_settings')]
#[IsGranted('configuration.settings.manage')]
final class NewsletterSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly NewsletterSettings $settings,
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
        $provider = mb_trim((string) ($payload['provider'] ?? $this->settings->provider()));
        $apiKey = array_key_exists('apiKey', $payload) ? mb_trim((string) $payload['apiKey']) : null;
        $listId = array_key_exists('listId', $payload) ? mb_trim((string) $payload['listId']) : null;

        if ($enabled && !$termsAccepted) {
            return $this->jsonFailure('backend.editorial.newsletter.errors.terms_required');
        }

        $keyAfterSave = $apiKey ?? $this->settings->apiKey();
        $listAfterSave = $listId ?? $this->settings->listId();

        if ($enabled && ('' === $keyAfterSave || '' === $listAfterSave)) {
            return $this->jsonFailure('backend.editorial.newsletter.errors.list_required');
        }

        $this->settings->save(
            enabled: $enabled,
            termsAccepted: $termsAccepted,
            provider: $provider,
            apiKey: $apiKey,
            listId: $listId,
            acceptedBy: (string) $this->getUser()?->getUserIdentifier(),
        );

        return $this->jsonSuccess($this->settings->state());
    }
}
