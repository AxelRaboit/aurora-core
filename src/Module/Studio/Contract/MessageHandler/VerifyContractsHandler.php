<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\MessageHandler;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Studio\Contract\Integrity\ContractIntegrityChecker;
use Aurora\Module\Studio\Contract\Message\VerifyContractsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The seal check, every morning, with somebody told when it fails.
 *
 * `aurora:contracts:verify` existed and nothing ran it: a detector nobody
 * switches on detects nothing. It now runs on the schedule, stays silent when
 * everything matches, and mails the administrator the list when something
 * does not, because a changed signed document is the one failure this module
 * cannot afford to learn about late.
 *
 * Not skipped when the contracts are switched off: the ones already signed
 * still have to be what was signed.
 */
#[AsMessageHandler]
final readonly class VerifyContractsHandler
{
    public function __construct(
        private ContractIntegrityChecker $checker,
        private MailService $mailService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(VerifyContractsMessage $message): void
    {
        // Not gated by the contracts switch, unlike the reminder and the
        // expiry, and on purpose: those two write to customers about a module
        // that is off, this one tells the administrator that sealed records
        // changed. Switching the screens off does not stop a row from being
        // altered, and the contracts stay on file for years after.
        $report = $this->checker->check();

        if ($report->isClean()) {
            return;
        }

        $this->logger->critical('Sealed contracts no longer match what was sealed.', [
            'altered' => $report->altered,
            'unverifiable' => $report->unverifiable,
        ]);

        $this->mailService->sendToAdmin(
            subjectKey: 'studio.email.contracts_integrity.subject',
            template: '@Studio/email/contracts_integrity.html.twig',
            context: ['report' => $report],
        );
    }
}
