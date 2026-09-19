<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Craft\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\SpaceNote\Craft\Setting\CraftSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function mb_trim;
use function str_starts_with;

/**
 * Lit et écrit l'onglet Craft de l'écran des réglages.
 *
 * Séparé de l'écran d'import, qui vit dans un espace : celui-ci décide qui a
 * le droit d'allumer l'intégration, et se range donc derrière le privilège des
 * réglages.
 */
#[Route('/backend/studio/craft/settings', name: 'backend_studio_craft_settings')]
#[IsGranted('configuration.settings.manage')]
final class CraftSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly CraftSettings $settings,
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
        $endpoint = mb_trim((string) ($payload['endpoint'] ?? ''));

        // Absent veut dire « laisse le jeton enregistré tranquille » ; présent
        // veut dire remplace-le, y compris par une chaîne vide pour l'oublier.
        // Le formulaire n'envoie le champ que si quelqu'un y a tapé.
        $token = array_key_exists('token', $payload) ? mb_trim((string) $payload['token']) : null;

        if ('' !== $endpoint && !str_starts_with($endpoint, 'https://')) {
            return $this->jsonFailure('backend.studio.craft.errors.endpoint_https');
        }

        // Vérifié contre ce qui sera enregistré et non contre ce qui l'est :
        // allumer l'intégration en ayant vidé le champ du jeton doit échouer,
        // et l'allumer avant qu'un jeton ait jamais été saisi aussi.
        $tokenAfterSave = $token ?? $this->settings->token();

        if ($enabled && ('' === $endpoint || '' === $tokenAfterSave)) {
            return $this->jsonFailure('backend.studio.craft.errors.connection_required');
        }

        $this->settings->save(enabled: $enabled, endpoint: $endpoint, token: $token);

        return $this->jsonSuccess($this->settings->state());
    }
}
