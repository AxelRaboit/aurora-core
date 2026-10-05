<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Pexels\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Ged\Pexels\Setting\PexelsSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reads and writes the Pexels tab of the settings screen.
 *
 * Separate from {@see PexelsController}, which the picker calls: this one is
 * about who may turn the integration on, and sits behind the settings
 * privilege rather than the document ones.
 */
#[Route('/suite/ged/pexels/settings', name: 'suite_ged_pexels_settings')]
#[IsGranted('configuration.settings.manage')]
final class PexelsSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PexelsSettings $settings,
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

        // Absent means "leave the stored key alone"; present means replace it,
        // including with an empty string to forget it. The form only sends the
        // field when somebody typed in it.
        $apiKey = array_key_exists('apiKey', $payload) ? mb_trim((string) $payload['apiKey']) : null;

        if ($enabled && !$termsAccepted) {
            return $this->jsonFailure('suite.ged.pexels.errors.terms_required');
        }

        // Checked against what will be stored, not against what is stored now:
        // saving the tab with the toggle on and the key field cleared has to
        // fail, and so does turning it on before any key was ever entered.
        $keyAfterSave = $apiKey ?? $this->settings->apiKey();
        if ($enabled && '' === $keyAfterSave) {
            return $this->jsonFailure('suite.ged.pexels.errors.key_required');
        }

        $this->settings->save(
            enabled: $enabled,
            termsAccepted: $termsAccepted,
            apiKey: $apiKey,
            // Who agreed, in the form the audit needs: an email an admin can
            // recognise a year later, not an id nobody can resolve.
            acceptedBy: (string) $this->getUser()?->getUserIdentifier(),
        );

        return $this->jsonSuccess($this->settings->state());
    }
}
