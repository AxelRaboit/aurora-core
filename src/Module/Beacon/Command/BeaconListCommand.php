<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Command;

use Aurora\Module\Beacon\Repository\DeployedInstanceRepository;
use Aurora\Module\Beacon\Service\KnownDomains;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;

/**
 * Lists the deployed instances the beacon has recorded, unknown ones first.
 *
 * The quick read surface for the beacon before the back-office screen exists:
 * run it to see every deployment that has checked in, and whether each is one
 * of yours (known domain, valid signature) or a lead to look into (see
 * LICENSE).
 */
#[AsCommand(name: 'aurora:beacon:list', description: 'List deployed instances recorded by the beacon')]
final class BeaconListCommand extends Command
{
    public function __construct(
        private readonly DeployedInstanceRepository $deployedInstanceRepository,
        private readonly KnownDomains $knownDomains,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $all = $this->deployedInstanceRepository->findAllOrdered();

        if ([] === $all) {
            $io->success('No instance has checked in yet.');
            $io->writeln('Known domains: '.implode(', ', $this->knownDomains->all()));

            return Command::SUCCESS;
        }

        $rows = [];
        $unknown = 0;
        foreach ($all as $instance) {
            $isLead = !$instance->isKnown();
            if ($isLead) {
                ++$unknown;
            }

            $rows[] = [
                $isLead ? 'LEAD' : 'ok',
                $instance->getDomain() ?? '-',
                $instance->getAppVersion() ?? '-',
                $instance->isSignatureValid() ? 'yes' : 'no',
                (string) $instance->getPingCount(),
                $instance->getLastSeenAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(['', 'Domain', 'Version', 'Signed', 'Pings', 'Last seen'], $rows);
        $io->writeln('Known domains: '.implode(', ', $this->knownDomains->all()));

        if ($unknown > 0) {
            $io->warning($unknown.' instance(s) on an unknown domain. Investigate.');
        } else {
            $io->success(count($all).' instance(s), all on known domains.');
        }

        return Command::SUCCESS;
    }
}
