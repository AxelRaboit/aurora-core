<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Captcha\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Captcha\CaptchaProviderEnum;
use Aurora\Module\Editorial\Captcha\CaptchaSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reads and writes the anti-robot tab of the settings screen.
 */
#[Route('/suite/editorial/captcha/settings', name: 'suite_editorial_captcha_settings')]
#[IsGranted('configuration.settings.manage')]
final class CaptchaSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly CaptchaSettings $settings,
    ) {}

    #[Route('', name: '_show', methods: [HttpMethodEnum::Get->value])]
    public function show(): JsonResponse
    {
        return $this->jsonSuccess($this->settings->adminView());
    }

    #[Route('', name: '_save', methods: [HttpMethodEnum::Post->value])]
    public function save(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $enabled = true === ($payload['enabled'] ?? false);
        $siteKey = mb_trim((string) ($payload['siteKey'] ?? ''));

        // Absent means "leave the stored secret alone"; present means replace
        // it, including with an empty string to forget it. The form only sends
        // the field when somebody typed in it.
        $secretKey = array_key_exists('secretKey', $payload)
            ? mb_trim((string) $payload['secretKey'])
            : null;

        $provider = CaptchaProviderEnum::tryFrom((string) ($payload['provider'] ?? ''))
            ?? CaptchaProviderEnum::Turnstile;

        // Checked against what will be stored rather than what is stored now:
        // turning the check on while clearing a key has to fail, or the form
        // it protects becomes impossible to submit.
        $secretAfterSave = $secretKey ?? $this->settings->secretKey();

        if ($enabled && ('' === $siteKey || '' === $secretAfterSave)) {
            return $this->jsonFailure('suite.parameters.captcha.errors.keys_required');
        }

        $this->settings->save(
            enabled: $enabled,
            provider: $provider,
            siteKey: $siteKey,
            secretKey: $secretKey,
        );

        return $this->jsonSuccess($this->settings->adminView());
    }
}
