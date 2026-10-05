<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Command;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\Probe\StorageProbe;
use Aurora\Core\Storage\Probe\StorageProbeStep;
use Aurora\Core\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * The console face of {@see StorageProbe}.
 *
 * Everything that decides whether a backend works lives in the probe, shared
 * with the settings screen: an operator running this and an administrator
 * pressing the button in the tab must not be able to get different answers.
 * What is here is presentation.
 */
#[AsCommand(
    name: 'aurora:storage:doctor',
    description: 'Write, read back and delete a witness object, to prove a storage backend works.',
)]
final class StorageDoctorCommand extends Command
{
    public function __construct(
        private readonly StorageManager $storageManager,
        private readonly StorageProbe $probe,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'disk',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Which backend to check (%s).', implode(', ', array_column(StorageDiskEnum::cases(), 'value'))),
            StorageDiskEnum::Local->value,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $requested = (string) $input->getOption('disk');
        $disk = StorageDiskEnum::tryFrom($requested);

        if (!$disk instanceof StorageDiskEnum) {
            $io->error(sprintf(
                'Unknown disk "%s". Known: %s.',
                $requested,
                implode(', ', array_column(StorageDiskEnum::cases(), 'value')),
            ));

            return Command::INVALID;
        }

        $io->title(sprintf('Storage doctor: %s', $disk->value));

        try {
            $adapter = $this->storageManager->forDisk($disk);
        } catch (StorageException $storageException) {
            $io->error($storageException->getMessage());

            return Command::FAILURE;
        }

        $result = $this->probe->run($adapter);

        foreach ($result->steps as $step) {
            $io->writeln(sprintf(
                '  %s  %s%s',
                $step->ok ? '<fg=green>ok</>' : '<fg=red>failed</>',
                $this->label($step),
                null !== $step->error ? sprintf(': %s', $step->error) : '',
            ));
        }

        $io->newLine();

        if (!$result->ok) {
            $io->error((string) $result->error);

            if (null !== $result->hint) {
                $io->note($this->translator->trans($result->hint));
            }

            return Command::FAILURE;
        }

        $io->success(sprintf('%s is working: written, read back, listed and deleted.', $disk->value));

        return Command::SUCCESS;
    }

    private function label(StorageProbeStep $step): string
    {
        return $this->translator->trans(sprintf('suite.settings.storage.probe.%s', $step->key));
    }
}
