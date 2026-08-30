<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Lists, and on `--force` removes, the files under `var/uploads/ged/` that no
 * database row points at any more.
 *
 * Two things leave files behind. Until v0.8.1 deleting a document erased only
 * its image variants, so its file, its thumbnail and every version file stayed
 * on disk forever - and since version rows disappear through an
 * `ON DELETE CASCADE`, nothing was left to even name them. That is fixed, but
 * the files from past deletions are still there.
 *
 * The second source is permanent and harmless: the upload endpoint writes the
 * bytes before the form is submitted, so an abandoned create form leaves a file
 * with no row. Which is why this refuses to touch anything recent - `--days`
 * (7 by default) keeps a file somebody is still working on out of reach.
 *
 * Dry by default. Nothing is deleted without `--force`.
 */
#[AsCommand(
    name: 'aurora:ged:prune-orphans',
    description: 'List (or remove) the GED files no database row points at.',
)]
final class PruneOrphanFilesCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly Filesystem $filesystem,
        #[Autowire(param: 'app.upload_dir')]
        private readonly string $uploadDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete. Without it, nothing is touched.');
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Spare files modified in the last N days.', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GED orphan files');

        $days = max(0, (int) $input->getOption('days'));
        $force = true === $input->getOption('force');

        $root = $this->uploadDir.'/'.StorageAreaEnum::Ged->value;
        if (!is_dir($root)) {
            $io->info(sprintf('Nothing to scan: %s does not exist.', $root));

            return Command::SUCCESS;
        }

        $referenced = $this->referencedPaths();
        $cutoff = time() - ($days * 86400);

        $orphans = [];
        $spared = 0;
        $bytes = 0;

        foreach ($this->files($root) as $file) {
            $relative = StorageAreaEnum::Ged->value.'/'.mb_ltrim(str_replace($root, '', $file->getPathname()), '/');
            if (isset($referenced[$relative])) {
                continue;
            }

            if ($file->getMTime() > $cutoff) {
                ++$spared;
                continue;
            }

            $orphans[] = $relative;
            $bytes += $file->getSize();
        }

        if ($spared > 0) {
            $io->text(sprintf('%d unreferenced file(s) left alone: modified less than %d day(s) ago.', $spared, $days));
        }

        if ([] === $orphans) {
            $io->success('No orphan file.');

            return Command::SUCCESS;
        }

        sort($orphans);
        $io->listing(array_slice($orphans, 0, 50));
        if (count($orphans) > 50) {
            $io->text(sprintf('... and %d more.', count($orphans) - 50));
        }

        $summary = sprintf('%d orphan file(s), %s.', count($orphans), $this->humanBytes($bytes));

        if (!$force) {
            $io->warning($summary.' Nothing deleted: pass --force.');

            return Command::SUCCESS;
        }

        foreach ($orphans as $relative) {
            $this->filesystem->remove($this->uploadDir.'/'.$relative);
        }

        $io->success($summary.' Deleted.');

        return Command::SUCCESS;
    }

    /**
     * Every relative path the database still names: a document's file, its
     * thumbnail, each of its generated variants, and every version file.
     *
     * @return array<string, true>
     */
    private function referencedPaths(): array
    {
        $paths = [];

        /** @var list<array{filePath: string|null, thumbnailPath: string|null, variants: array<string, string>}> $documents */
        $documents = $this->documentRepository->createQueryBuilder('d')
            ->select('d.filePath', 'd.thumbnailPath', 'd.variants')
            ->getQuery()
            ->getResult();

        foreach ($documents as $document) {
            foreach ([$document['filePath'], $document['thumbnailPath']] as $path) {
                if (null !== $path && '' !== $path) {
                    $paths[$path] = true;
                }
            }

            foreach ($document['variants'] as $variant) {
                if ('' !== $variant) {
                    $paths[$variant] = true;
                }
            }
        }

        /** @var list<array{filePath: string}> $versions */
        $versions = $this->versionRepository->createQueryBuilder('v')
            ->select('v.filePath')
            ->getQuery()
            ->getResult();

        foreach ($versions as $version) {
            $paths[$version['filePath']] = true;
        }

        return $paths;
    }

    /** @return iterable<SplFileInfo> */
    private function files(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 'KB';
        foreach ($units as $candidate) {
            $unit = $candidate;
            if ($value < 1024) {
                break;
            }

            $value /= 1024;
        }

        return sprintf('%.1f %s', $value, $unit);
    }
}
