<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Setting;

use Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface;

/**
 * Declares {@see EditorialSettingEnum} to the deploy-time settings sync.
 *
 * `aurora:application-parameter` deletes every `core_settings` row no
 * provider vouches for. The editorial reference prefixes implemented the settings interface and
 * were drawn by the settings screen, but nothing yielded them: whatever an
 * administrator saved was gone after the next release, and the page quietly
 * fell back to the defaults. Found on 27/09/2026 - no such row existed in
 * production.
 */
final readonly class EditorialApplicationParameterProvider implements ApplicationParameterProviderInterface
{
    public function getParameters(): iterable
    {
        yield from EditorialSettingEnum::cases();
    }
}
