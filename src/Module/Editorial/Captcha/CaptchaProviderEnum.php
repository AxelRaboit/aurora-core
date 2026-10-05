<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Captcha;

/**
 * The two services the comment form can be checked against.
 *
 * Turnstile first, and it is the one the tab recommends: it asks the reader
 * nothing, sets no advertising cookie, and its verification endpoint answers
 * the same shape as Google's, so supporting both costs one match arm rather
 * than a second implementation.
 *
 * reCAPTCHA is here because it is the one everybody names, and because a
 * client who already has keys should not be told to go and get others. It
 * scores rather than passes: v3 answers with a number between 0 and 1, and
 * anything under {@see self::RECAPTCHA_THRESHOLD} is treated as a robot.
 */
enum CaptchaProviderEnum: string
{
    case Turnstile = 'turnstile';
    case Recaptcha = 'recaptcha';

    /**
     * Google's own suggested cut-off. Low enough that a human on a VPN still
     * gets through, high enough to stop a script that never moves a mouse.
     */
    public const float RECAPTCHA_THRESHOLD = 0.5;

    public function verifyUrl(): string
    {
        return match ($this) {
            self::Turnstile => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            self::Recaptcha => 'https://www.google.com/recaptcha/api/siteverify',
        };
    }

    /**
     * The script the page loads.
     *
     * The two work differently and the URL is where that starts. Turnstile
     * draws a widget, so the page renders one explicitly once the script is
     * there. reCAPTCHA v3 draws nothing and scores behaviour instead, so its
     * script is loaded for one site key and asked for a token at submit time.
     */
    public function scriptUrl(string $siteKey): string
    {
        return match ($this) {
            self::Turnstile => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            self::Recaptcha => 'https://www.google.com/recaptcha/api.js?render='.rawurlencode($siteKey),
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Turnstile => 'suite.parameters.captcha.providers.turnstile',
            self::Recaptcha => 'suite.parameters.captcha.providers.recaptcha',
        };
    }
}
