<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function mb_trim;

/**
 * Lit et écrit l'onglet Google Drive de l'écran des réglages.
 */
#[Route('/backend/studio/drive/settings', name: 'backend_studio_drive_settings')]
#[IsGranted('configuration.settings.manage')]
final class DriveSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly DriveSettings $settings,
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

        // Absent veut dire « laisse la clé enregistrée tranquille ».
        $key = array_key_exists('serviceAccount', $payload)
            ? mb_trim((string) $payload['serviceAccount'])
            : null;

        // **Refusée ici plutôt qu'au premier appel.** Une clé collée de
        // travers - le fichier à moitié sélectionné, une clé OAuth prise pour
        // une clé de compte de service - donnerait sinon une intégration qui
        // s'allume et ne répond jamais, sans dire pourquoi.
        if (null !== $key && '' !== $key && !GoogleServiceAccount::fromJson($key) instanceof GoogleServiceAccount) {
            return $this->jsonFailure('backend.studio.drive.errors.key_invalid');
        }

        $this->settings->save(enabled: $enabled, serviceAccount: $key);

        if ($enabled && !$this->settings->isEnabled()) {
            return $this->jsonFailure('backend.studio.drive.errors.key_required');
        }

        return $this->jsonSuccess($this->settings->state());
    }
}
