<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Command;

use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Service\ContractRetentionPolicy;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * Says what the archive holds and what has aged out of it.
 *
 * It reports and never deletes, and that is the design rather than a missing
 * flag. A retention that expires is permission to destroy evidence, not an
 * instruction: the document may still be wanted for a dispute nobody has told
 * the software about, and a nightly job that quietly emptied the archive would
 * be the one bug in this module with no way back.
 *
 * So the ageing out is surfaced here and the deletion stays a human act, taken
 * one contract at a time in the back-office, where the manager checks the same
 * date again before allowing it.
 */
#[AsCommand(
    name: 'aurora:contracts:retention',
    description: 'Report the retention of every sealed contract and which are past it',
)]
final class ContractRetentionCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ContractRetentionPolicy $contractRetentionPolicy,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $years = $this->contractRetentionPolicy->years();
        $now = new DateTimeImmutable();

        $io->title(sprintf('Conservation des contrats scellés : %d ans', $years));

        $rows = [];
        $elapsed = 0;

        foreach ($this->contractRepository->findFrozen() as $contract) {
            $until = $contract->retainedUntil($years);
            $past = $until instanceof DateTimeImmutable && $until <= $now;

            if ($past) {
                ++$elapsed;
            }

            $rows[] = [
                $contract->getReference() ?? '-',
                $contract->getCustomer()->getLegalName(),
                $contract->getStatus()->value,
                $contract->getFrozenAt()?->format('d/m/Y') ?? '-',
                $until?->format('d/m/Y') ?? '-',
                $past ? 'échue' : 'en cours',
            ];
        }

        if ([] === $rows) {
            $io->info('Aucun contrat scellé.');

            return Command::SUCCESS;
        }

        $io->table(['Référence', 'Client', 'Statut', 'Scellé le', 'Conservé jusqu\'au', 'Conservation'], $rows);

        // Success either way: a contract past its retention is not a fault to
        // fail a deployment on, it is a decision waiting for somebody.
        $io->writeln(sprintf(
            '%d contrat(s) scellé(s), dont %d dont la conservation est échue.',
            count($rows),
            $elapsed,
        ));

        return Command::SUCCESS;
    }
}
