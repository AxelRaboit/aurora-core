<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills the JPEG thumbnail for every PDF GED Document that still has
 * `thumbnail_path` NULL.
 *
 * Run after the migration that added the column, or after importing a
 * batch of docs through means other than the upload endpoint (e.g. a
 * data migration from another system). Idempotent - re-running won't
 * regenerate thumbs that already exist unless `--force` is passed.
 */
#[AsCommand(
    name: 'aurora:ged:thumbnails:generate',
    description: 'Generate the missing JPEG thumbnails for PDF GED documents.',
)]
final class GenerateThumbnailsCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PdfThumbnailGenerator $thumbnailGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate even when a thumbnail already exists.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Generating GED document thumbnails');

        $force = (bool) $input->getOption('force');
        $documents = $this->documentRepository->findBy(['mimeType' => MimeTypeEnum::Pdf->value]);

        if ([] === $documents) {
            $io->info('No PDF documents found.');

            return Command::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($documents as $document) {
            $filePath = $document->getFilePath();
            if (null === $filePath) {
                continue;
            }

            if (!$force && null !== $document->getThumbnailPath()) {
                ++$skipped;
                continue;
            }

            $thumbDir = $this->thumbDirFor($document);
            $basename = pathinfo($document->getFileName() ?? (string) $document->getId(), PATHINFO_FILENAME);
            $thumbPath = $this->thumbnailGenerator->generate($filePath, $thumbDir, $basename);

            if (null === $thumbPath) {
                $io->warning(sprintf('Failed for #%d (%s)', $document->getId(), $document->getTitle()));
                ++$failed;
                continue;
            }

            $document->setThumbnailPath($thumbPath);
            ++$generated;
            $io->writeln(sprintf('  ✓ #%d <info>%s</info>', $document->getId(), $document->getTitle()));
        }

        $this->entityManager->flush();

        $io->success(sprintf('Done. Generated: %d  Skipped: %d  Failed: %d', $generated, $skipped, $failed));

        return Command::SUCCESS;
    }

    private function thumbDirFor(DocumentInterface $document): string
    {
        return sprintf('%s/thumbnails/%s', StorageAreaEnum::Ged->value, $document->getCreatedAt()->format('Y/m'));
    }
}
