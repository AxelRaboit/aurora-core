<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\Craft\Setting\CraftSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function mb_trim;
use function str_starts_with;

/**
 * Reads and writes the Craft tab of the settings screen.
 *
 * Separate from the import, which lives in the notes screen: this one decides
 * who is allowed to turn the integration on, and so sits behind the settings
 * privilege.
 */
#[Route('/suite/notes/craft/settings', name: 'suite_notes_craft_settings')]
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

        // Absent means "leave the saved token alone"; present means replace
        // it, including with an empty string to forget it.
        // The form only sends the field if someone typed in it.
        $token = array_key_exists('token', $payload) ? mb_trim((string) $payload['token']) : null;

        if ('' !== $endpoint && !str_starts_with($endpoint, 'https://')) {
            return $this->jsonFailure('notes.craft.errors.endpoint_https');
        }

        // Checked against what will be saved and not against what is saved:
        // turning the integration on after emptying the token field must fail,
        // and so must turning it on before a token was ever entered.
        $tokenAfterSave = $token ?? $this->settings->token();

        if ($enabled && ('' === $endpoint || '' === $tokenAfterSave)) {
            return $this->jsonFailure('notes.craft.errors.connection_required');
        }

        $this->settings->save(enabled: $enabled, endpoint: $endpoint, token: $token);

        return $this->jsonSuccess($this->settings->state());
    }
}
