<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use DateTimeImmutable;

use function max;

/**
 * How long a sealed contract has to be kept.
 *
 * Its own service because three places need the same answer and none of them
 * should own it: the manager refuses a deletion with it, the serializer shows a
 * date with it, and the command reports on it. Read from the setting each time
 * rather than stored on the row - a retention that changed would otherwise
 * leave old contracts quoting the old rule, and the rule is a policy, not a
 * property of the document.
 */
final readonly class ContractRetentionPolicy
{
    /**
     * The floor the setting cannot go under.
     *
     * Five years is the retention a commercial obligation carries in France. A
     * field emptied, zeroed or typed wrong must not be able to say "delete
     * signed contracts freely", so it lands here instead.
     */
    public const int MINIMUM_YEARS = 5;

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    public function years(): int
    {
        return max(self::MINIMUM_YEARS, (int) $this->settingRepository->getOrDefault(ApplicationParameterEnum::StudioContractRetentionYears));
    }

    /** The day the evidence stops being required, or null before the seal. */
    public function until(ContractInterface $contract): ?DateTimeImmutable
    {
        return $contract->retainedUntil($this->years());
    }

    public function hasElapsed(ContractInterface $contract, ?DateTimeImmutable $now = null): bool
    {
        $until = $this->until($contract);

        return $until instanceof DateTimeImmutable && $until <= ($now ?? new DateTimeImmutable());
    }
}
