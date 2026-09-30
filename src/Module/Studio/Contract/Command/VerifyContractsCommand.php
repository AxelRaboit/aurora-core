<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Command;

use Aurora\Module\Studio\Contract\Integrity\ContractIntegrityChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Recomputes every frozen contract's hash and says what moved.
 *
 * This is the mesure that turns the snapshot from a hope into a fact. The
 * other five in that phase prevent drift; this one detects it. Without it, a
 * document altered by a bad migration, a manual database edit or a bug in a
 * later refactor stays altered and nobody learns, which is precisely the
 * failure mode the whole design is built against: silent, and irreversible by
 * the time it surfaces.
 *
 * The same check runs every morning on its own and mails the administrator
 * when something moved (`VerifyContractsHandler`); this command is for after
 * a deployment that touched this module, or to see the list at once. It
 * reads and never writes, so running it is always safe.
 *
 * Exit code 1 on any mismatch, so a scheduler or a CI step fails on it rather
 * than printing red text nobody reads.
 */
#[AsCommand(
    name: 'aurora:contracts:verify',
    description: 'Check every sealed contract, its signatures and its signed PDF against their hashes',
)]
final class VerifyContractsCommand extends Command
{
    public function __construct(
        private readonly ContractIntegrityChecker $checker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->checker->check();

        if (0 === $report->checked) {
            $io->success('No frozen contract to verify.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('%d frozen contract(s) checked.', $report->checked));

        if ([] !== $report->unverifiable) {
            $io->warning('These contracts could not be verified by this version of the code:');
            $io->listing($report->unverifiable);
        }

        if ([] !== $report->altered) {
            $io->error('These contracts no longer match what was sealed:');
            $io->listing($report->altered);
            $io->writeln('A signed document has changed since it was sealed. Do not repair the hash: find out what wrote to it.');

            return Command::FAILURE;
        }

        if ([] !== $report->unverifiable) {
            return Command::FAILURE;
        }

        $io->success(sprintf('Every one of the %d frozen contracts still matches what was sealed.', $report->checked));

        return Command::SUCCESS;
    }
}
