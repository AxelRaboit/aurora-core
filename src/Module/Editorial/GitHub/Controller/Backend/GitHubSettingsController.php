<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\GitHub\Setting\GitHubSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function count;
use function implode;
use function is_string;

/**
 * Lit et écrit l'onglet GitHub de l'écran des réglages.
 */
#[Route('/backend/editorial/github/settings', name: 'backend_editorial_github_settings')]
#[IsGranted('configuration.settings.manage')]
final class GitHubSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly GitHubSettings $settings,
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
        $input = is_string($payload['logins'] ?? null) ? $payload['logins'] : '';

        [$logins, $invalid] = GitHubSettings::parse($input);

        // Refusé plutôt que retiré en silence : un identifiant mal recopié
        // qui disparaîtrait à l'enregistrement laisserait croire que le
        // compte est affiché.
        if ([] !== $invalid) {
            return $this->jsonFailure('backend.editorial.github.errors.invalid_login', extra: ['logins' => implode(', ', $invalid)]);
        }

        if (GitHubSettings::MAX_LOGINS < count($logins)) {
            return $this->jsonFailure('backend.editorial.github.errors.too_many', extra: ['max' => GitHubSettings::MAX_LOGINS]);
        }

        if ($enabled && [] === $logins) {
            return $this->jsonFailure('backend.editorial.github.errors.logins_required');
        }

        $this->settings->save(enabled: $enabled, logins: $logins);

        return $this->jsonSuccess($this->settings->state());
    }
}
