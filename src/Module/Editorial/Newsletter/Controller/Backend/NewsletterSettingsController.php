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
use function in_array;
use function mb_trim;
use function preg_match;

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
        $doubleOptIn = array_key_exists('doubleOptIn', $payload) ? true === $payload['doubleOptIn'] : null;
        $brevoTemplateId = array_key_exists('brevoTemplateId', $payload) ? mb_trim((string) $payload['brevoTemplateId']) : null;
        $privacyUrl = array_key_exists('privacyUrl', $payload) ? mb_trim((string) $payload['privacyUrl']) : null;

        if ($enabled && !$termsAccepted) {
            return $this->jsonFailure('backend.editorial.newsletter.errors.terms_required');
        }

        if (null !== $privacyUrl && '' !== $privacyUrl && !NewsletterSettings::isPrivacyUrl($privacyUrl)) {
            return $this->jsonFailure('backend.editorial.newsletter.errors.privacy_url_invalid');
        }

        if (null !== $brevoTemplateId && '' !== $brevoTemplateId && 1 !== preg_match('/^[1-9]\d{0,9}$/', $brevoTemplateId)) {
            return $this->jsonFailure('backend.editorial.newsletter.errors.template_invalid');
        }

        // The GDPR asks that a visitor be told, where the address is taken,
        // who receives it and how to withdraw: no policy, no form.
        if ($enabled && '' === ($privacyUrl ?? (string) $this->settings->privacyUrl())) {
            return $this->jsonFailure('backend.editorial.newsletter.errors.privacy_required');
        }

        $providerAfterSave = in_array($provider, NewsletterSettings::PROVIDERS, true) ? $provider : NewsletterSettings::PROVIDERS[0];
        $doubleOptInAfterSave = $doubleOptIn ?? $this->settings->doubleOptIn();
        $templateAfterSave = $brevoTemplateId ?? (string) $this->settings->brevoTemplateId();

        if ($enabled && 'brevo' === $providerAfterSave && $doubleOptInAfterSave && '' === $templateAfterSave) {
            return $this->jsonFailure('backend.editorial.newsletter.errors.template_required');
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
            doubleOptIn: $doubleOptIn,
            brevoTemplateId: $brevoTemplateId,
            privacyUrl: $privacyUrl,
        );

        return $this->jsonSuccess($this->settings->state());
    }
}
