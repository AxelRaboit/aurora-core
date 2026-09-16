<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Command;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Orphan\ReferencedKeysProviderInterface;
use Aurora\Core\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_slice;
use function count;
use function sort;
use function sprintf;
use function time;

/**
 * Lists, and on `--force` removes, the stored files no database row points at.
 *
 * Two things leave files behind. Until v0.8.1 deleting a GED document erased
 * only its image variants, so its file, its thumbnail and every version file
 * stayed on disk forever - and since version rows disappear through an
 * `ON DELETE CASCADE`, nothing was left to even name them. That is fixed, but
 * the files from past deletions are still there.
 *
 * The second source is permanent and harmless: an upload endpoint writes the
 * bytes before the form is submitted, so an abandoned create form leaves a file
 * with no row. Which is why this refuses to touch anything recent - `--days`
 * (7 by default) keeps a file somebody is still working on out of reach.
 *
 * **It used to sweep `ged/` and nothing else**, which meant a replaced profile
 * photo, the PDF of a deleted contract, and everything under any area a client
 * project had added accumulated unwatched. Each area now answers for itself
 * through a {@see ReferencedKeysProviderInterface}, the way every other
 * cross-module surface in Aurora works.
 *
 * **An area with no provider is skipped, not swept**, and the direction
 * matters: a sweep that cannot name what a module references would delete that
 * module's files. `--verbose` says which areas were skipped and why. Notes
 * images are the standing example - they are referenced from inside note
 * bodies, which are encrypted, so naming them would mean decrypting every note
 * in the database. The module cleans up after its own edits instead.
 *
 * Dry by default. Nothing is deleted without `--force`.
 *
 * Note on `--days`: it reads the stored object's modification date, which on a
 * remote backend is when the object was put there rather than when the file
 * was made. Right after a migration everything looks new, and this spares all
 * of it until the window passes. Prudent rather than wrong, but surprising if
 * you do not know it.
 */
#[AsCommand(
    name: 'aurora:storage:prune-orphans',
    description: 'List (or remove) the stored files no database row points at.',
    aliases: ['aurora:ged:prune-orphans'],
)]
final class PruneOrphanFilesCommand extends Command
{
    /**
     * @param iterable<ReferencedKeysProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('aurora.referenced_keys_provider')]
        private readonly iterable $providers,
        private readonly StorageManager $storageManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete. Without it nothing is touched.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Spare files modified less than this many days ago.', '7')
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Sweep only this area, e.g. "ged".');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $days = max(0, (int) $input->getOption('days'));
        $only = $input->getOption('area');
        $adapter = $this->storageManager->active();
        $cutoff = time() - ($days * 86400);

        $orphansByArea = [];
        $totalBytes = 0;
        $totalSpared = 0;

        foreach (StorageAreaEnum::cases() as $area) {
            if (null !== $only && $only !== $area->value) {
                continue;
            }

            $referenced = $this->referencedKeysFor($area);

            if (null === $referenced) {
                $io->writeln(
                    sprintf(
                        '<comment>%s</comment>: skipped, no provider names what it references.',
                        $area->value,
                    ),
                    OutputInterface::VERBOSITY_VERBOSE,
                );

                continue;
            }

            // One listing per area, and every size and date comes with it.
            // Asking the backend again per file would be free on a disk and
            // one billed request per file anywhere else.
            foreach ($adapter->list($area->value) as $object) {
                if (isset($referenced[$object->key])) {
                    continue;
                }

                if ($object->lastModifiedAt->getTimestamp() > $cutoff) {
                    ++$totalSpared;

                    continue;
                }

                $orphansByArea[$area->value][] = $object->key;
                $totalBytes += $object->size;
            }
        }

        if ($totalSpared > 0) {
            $io->text(sprintf('%d unreferenced file(s) left alone: modified less than %d day(s) ago.', $totalSpared, $days));
        }

        $orphans = [];
        foreach ($orphansByArea as $area => $keys) {
            sort($keys);
            $io->section(sprintf('%s - %d orphan(s)', $area, count($keys)));
            $io->listing(array_slice($keys, 0, 50));
            if (count($keys) > 50) {
                $io->text(sprintf('... and %d more.', count($keys) - 50));
            }

            $orphans = [...$orphans, ...$keys];
        }

        if ([] === $orphans) {
            $io->success('No orphan file.');

            return Command::SUCCESS;
        }

        $summary = sprintf('%d orphan file(s), %s.', count($orphans), $this->humanBytes($totalBytes));

        if (!$force) {
            $io->warning($summary.' Nothing deleted: pass --force.');

            return Command::SUCCESS;
        }

        // In one call rather than one per orphan: this command exists to clean
        // up after thousands of them.
        $adapter->deleteMany($orphans);

        $io->success($summary.' Deleted.');

        return Command::SUCCESS;
    }

    /**
     * Every key this area's owners still name, or null when nobody owns it.
     *
     * Null and an empty set are different answers and must stay so: "nothing
     * references anything here" is a mandate to delete the lot, and "nobody
     * said" is a mandate to leave it alone.
     *
     * @return array<string, true>|null
     */
    private function referencedKeysFor(StorageAreaEnum $area): ?array
    {
        $keys = null;

        foreach ($this->providers as $provider) {
            if ($provider->area() !== $area) {
                continue;
            }

            $keys ??= [];

            foreach ($provider->referencedKeys() as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
