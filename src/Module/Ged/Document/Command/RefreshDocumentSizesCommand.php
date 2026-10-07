<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\StorageManager;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function abs;
use function sprintf;

/**
 * Resets a document's recorded size to that of the stored file.
 *
 * **Why they drift apart.** The size is taken when the file arrives, and a
 * JPEG source is re-encoded in place at quality 85 - metadata included - when
 * its generated sizes are produced. The number therefore stopped being true
 * one line later, and the media library showed a size unrelated to what is
 * stored. Measured on an import: one and a half million bytes announced for
 * two hundred thousand on disk.
 *
 * {@see DocumentManager::regenerateRenditionsIfImage()} now reads it again
 * afterwards, so nothing new comes in wrong. This command is for what is
 * already there.
 *
 * Idempotent: running it again on a healthy library changes nothing and says
 * nothing. `--dry-run` counts without writing.
 */
#[AsCommand(
    name: 'aurora:ged:sizes:refresh',
    description: "Reset each document's recorded size to the size of the stored file.",
)]
final class RefreshDocumentSizesCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageManager $storageManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Refreshing GED document sizes');

        $dryRun = (bool) $input->getOption('dry-run');
        $corrected = 0;
        $missing = 0;
        $drift = 0;

        foreach ($this->documentRepository->findAll() as $document) {
            $filePath = $document->getFilePath();

            if (null === $filePath) {
                continue;
            }

            $stored = $this->storageManager->forDisk($document->getStorageDisk())->stat($filePath);

            if (!$stored instanceof StoredObject) {
                // A missing file is not a size to fix, it is another problem -
                // and overwriting it with zero would hide it.
                ++$missing;

                continue;
            }

            $recorded = (int) $document->getSize();

            if ($recorded === $stored->size) {
                continue;
            }

            ++$corrected;
            $drift += abs($recorded - $stored->size);

            $io->text(sprintf(
                '#%d %s : %d → %d',
                $document->getId(),
                $document->getTitle(),
                $recorded,
                $stored->size,
            ));

            if (!$dryRun) {
                $document->setSize($stored->size);
            }
        }

        if (!$dryRun && 0 !== $corrected) {
            $this->entityManager->flush();
        }

        if (0 !== $missing) {
            $io->warning(sprintf('%d document(s) have no file where their record points.', $missing));
        }

        if (0 === $corrected) {
            $io->success('Every recorded size already matches its stored file.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d size(s) %s, %d bytes of drift.',
            $corrected,
            $dryRun ? 'would change' : 'corrected',
            $drift,
        ));

        return Command::SUCCESS;
    }
}
