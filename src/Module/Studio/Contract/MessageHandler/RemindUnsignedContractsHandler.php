<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\MessageHandler;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Contract\Access\Manager\ContractAccessLinkManagerInterface;
use Aurora\Module\Studio\Contract\Message\RemindUnsignedContractsMessage;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\StudioContext;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

/**
 * Sends the reminders that are due, and nothing else.
 *
 * Four gates, and each one is there because automatic mail to somebody else's
 * customer is the kind of feature that becomes a complaint:
 *
 * 1. **Off unless turned on.** The setting defaults to off, so upgrading the
 *    bundle never starts mailing anybody's customers.
 * 2. **A ceiling.** After the configured number of reminders the contract is
 *    left alone. Silence is an answer too.
 * 3. **A delay measured from the last contact**, not from the seal, so the
 *    first chase lands the configured number of days after the contract
 *    actually went out.
 * 4. **Only what is still waiting.** Signed, concluded, refused, expired and
 *    revoked are answers; the query excludes them rather than trusting this
 *    loop to remember.
 *
 * A refusal at the same moment as the run is the interesting race, and the
 * hand-out refuses it: it throws on an engaged contract, and the exception is
 * logged and skipped rather than failing the whole run. One contract that
 * moved under the query must not stop the others from being chased.
 */
#[AsMessageHandler]
final readonly class RemindUnsignedContractsHandler
{
    public function __construct(
        private ContractRepository $contracts,
        private ContractAccessLinkManagerInterface $links,
        private SettingRepository $settings,
        private LoggerInterface $logger,
        private StudioContext $studio,
    ) {}

    public function __invoke(RemindUnsignedContractsMessage $message): void
    {
        // Contracts switched off answer 404 on the signing page, so a reminder
        // would mail a customer a link that leads nowhere.
        if (!$this->studio->areContractsEnabled()) {
            return;
        }

        if (!$this->settings->getBoolean(ApplicationParameterEnum::StudioContractReminderEnabled->value)) {
            return;
        }

        $days = $this->positiveInt(ApplicationParameterEnum::StudioContractReminderDays);
        $max = $this->positiveInt(ApplicationParameterEnum::StudioContractReminderMax);

        if (0 === $days || 0 === $max) {
            // A zero in either field is a reader saying "not this": honoured
            // rather than corrected into a default they did not ask for.
            return;
        }

        $before = new DateTimeImmutable(sprintf('-%d days', $days));
        $due = $this->contracts->findDueForReminder($before, $max);

        foreach ($due as $contract) {
            try {
                $this->links->remind($contract);
            } catch (FieldException $fieldException) {
                $this->logger->warning('Contract reminder skipped', [
                    'contract' => $contract->getId(),
                    'reference' => $contract->getReference(),
                    'reason' => $fieldException->getMessage(),
                ]);
            }
        }
    }

    /**
     * A setting read as a count, with nonsense treated as zero.
     *
     * Zero means "do not", which is the safe reading of a field somebody has
     * emptied or typed a word into: it stops the mail rather than falling back
     * to a number nobody chose.
     */
    private function positiveInt(ApplicationParameterEnum $parameter): int
    {
        $value = (int) $this->settings->getOrDefault($parameter);

        return max($value, 0);
    }
}
