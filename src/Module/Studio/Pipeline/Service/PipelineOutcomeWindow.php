<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Contract\Service\ContractLinkLifetime;

use function max;
use function min;

/**
 * How many days a won or lost deal stays on the prospect board.
 *
 * A setting rather than the constant it was in 4.10.0: how far back the
 * outcome columns should look depends on how many deals a studio closes, and
 * everything else Aurora shows is adjustable from the back office. Read each
 * time, like {@see ContractLinkLifetime}, and bounded, so an emptied field
 * neither empties the outcome columns nor turns them into an archive.
 */
final readonly class PipelineOutcomeWindow
{
    public const int MINIMUM_DAYS = 1;

    public const int MAXIMUM_DAYS = 365;

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    public function days(): int
    {
        $days = (int) $this->settingRepository->getOrDefault(ApplicationParameterEnum::StudioPipelineOutcomeDays);

        return min(self::MAXIMUM_DAYS, max(self::MINIMUM_DAYS, $days));
    }
}
