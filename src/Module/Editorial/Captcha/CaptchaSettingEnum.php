<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Captcha;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * The four rows the anti-robot check keeps in the settings table.
 *
 * Deliberately not an {@see ApplicationParameterEnumInterface}, for the same
 * reason the Pexels keys are not: that interface exists so the generic
 * settings screen can draw a field and ship its current value to the browser,
 * and a secret key has no business being echoed into a page.
 *
 * The site key is not a secret - it is printed in the page by design - but it
 * lives here with the rest so one screen owns the whole integration.
 */
enum CaptchaSettingEnum: string
{
    case Enabled = 'suite_editorial_captcha_enabled';

    /** `turnstile` or `recaptcha`; see {@see CaptchaProviderEnum}. */
    case Provider = 'suite_editorial_captcha_provider';

    /** Printed in the page, on purpose: the widget needs it. */
    case SiteKey = 'suite_editorial_captcha_site_key';

    /** Stored encrypted; see {@see CaptchaSettings}. */
    case SecretKey = 'suite_editorial_captcha_secret_key';
}
