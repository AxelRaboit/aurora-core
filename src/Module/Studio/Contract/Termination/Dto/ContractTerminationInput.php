<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Termination\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What ends a contract: two dates, an origin, and optionally a reason.
 *
 * Two dates and not one, because a notice period is exactly the gap between
 * them. A contract noticed today and effective in thirty days is still running
 * for a month, and recording only the second date would lose the fact that
 * notice was given on time - which is the one thing a dispute about a notice
 * period turns on.
 */
class ContractTerminationInput implements ContractTerminationInputInterface
{
    public const int MAX_REASON_LENGTH = 2000;

    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.contracts.errors.termination_noticed_required')]
        #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'suite.studio.contracts.errors.termination_date_invalid')]
        public readonly string $noticedAt = '',
        #[Assert\NotBlank(message: 'suite.studio.contracts.errors.termination_effective_required')]
        #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'suite.studio.contracts.errors.termination_date_invalid')]
        public readonly string $effectiveAt = '',
        #[Assert\NotBlank(message: 'suite.studio.contracts.errors.termination_origin_required')]
        public readonly string $origin = '',
        #[Assert\Length(max: self::MAX_REASON_LENGTH, maxMessage: 'suite.studio.contracts.errors.termination_reason_too_long')]
        public readonly string $reason = '',
    ) {}

    public function getNoticedAt(): string
    {
        return $this->noticedAt;
    }

    public function getEffectiveAt(): string
    {
        return $this->effectiveAt;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
