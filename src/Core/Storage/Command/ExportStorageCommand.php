<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Command;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

use function array_column;
use function explode;
use function filesize;
use function getcwd;
use function implode;
use function is_file;
use function ksort;
use function mb_rtrim;
use function sprintf;
use function str_starts_with;

/**
 * Copies every object of a disk into a plain directory, keys becoming paths.
 *
 * Read-only on the disk: it lists and downloads, nothing else. The result is
 * laid out exactly like the local disk, so the directory can serve as another
 * install's `var/uploads` as it is - which is what copying a production
 * library to a development machine needs, without its bucket credentials ever
 * leaving the server.
 *
 * A file already present at the same size is skipped, so running it again
 * only downloads what changed since.
 */
#[AsCommand(
    name: 'aurora:storage:export',
    description: 'Copy every object of a storage disk into a local directory, without touching the disk.',
)]
final class ExportStorageCommand extends Command
{
    public function __construct(
        private readonly StorageManager $storageManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::REQUIRED, 'The directory to copy into.')
            ->addOption(
                'disk',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Which backend to read (%s).', implode(', ', array_column(StorageDiskEnum::cases(), 'value'))),
                StorageDiskEnum::R2->value,
            )
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Only the keys under this prefix.', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and weigh what would be copied, per top-level folder.');
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

        $target = mb_rtrim(Path::makeAbsolute((string) $input->getArgument('target'), (string) getcwd()), '/');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $adapter = $this->storageManager->forDisk($disk);

            $copied = 0;
            $skipped = 0;
            $bytes = 0;
            /** @var array<string, array{int, int}> $folders */
            $folders = [];

            foreach ($adapter->list((string) $input->getOption('prefix')) as $object) {
                $destination = Path::canonicalize($target.'/'.$object->key);

                // A key is data from the bucket: one that climbs out of the
                // target must not get to write wherever it points.
                if (!str_starts_with($destination, $target.'/')) {
                    $io->warning(sprintf('Skipped "%s": it resolves outside the target.', $object->key));

                    continue;
                }

                $folder = explode('/', $object->key)[0];
                $folders[$folder] = [($folders[$folder][0] ?? 0) + 1, ($folders[$folder][1] ?? 0) + $object->size];

                if ($dryRun) {
                    continue;
                }

                if (is_file($destination) && filesize($destination) === $object->size) {
                    ++$skipped;

                    continue;
                }

                $adapter->copyToLocalFile($object->key, $destination);
                ++$copied;
                $bytes += $object->size;
            }
        } catch (StorageException $storageException) {
            $io->error($storageException->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            ksort($folders);
            $rows = [];
            foreach ($folders as $folder => [$count, $size]) {
                $rows[] = [$folder, $count, sprintf('%.1f MB', $size / 1_048_576)];
            }

            $io->table(['Folder', 'Objects', 'Size'], $rows);

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s: %d copied (%.1f MB), %d already up to date, into %s.',
            $disk->value,
            $copied,
            $bytes / 1_048_576,
            $skipped,
            $target,
        ));

        return Command::SUCCESS;
    }
}
