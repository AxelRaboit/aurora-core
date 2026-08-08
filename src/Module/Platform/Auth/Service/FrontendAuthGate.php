<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

/**
 * Whether this installation has front-end accounts at all.
 *
 * The same core ships to sites that need visitor accounts and to brochure
 * sites that never will. Rather than have the latter delete routes or
 * override templates, the whole front auth surface hangs off one parameter,
 * so "no login here" is a setting rather than a fork.
 *
 * Registration keeps its own flag on top of this one: open registration on a
 * site with no login would be a form leading nowhere, so `frontend_login_enabled`
 * gates it rather than sitting beside it.
 */
final readonly class FrontendAuthGate
{
    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    /**
     * Defaults to enabled: the setting row only exists once
     * `aurora:application-parameter` has run, and an install part-way through
     * that command should keep the accounts it already had.
     */
    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(
            ApplicationParameterEnum::FrontLoginEnabled->value,
            true,
        );
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->isEnabled()
            && $this->settingRepository->getBoolean(ApplicationParameterEnum::FrontRegistrationEnabled->value);
    }
}
