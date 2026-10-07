<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Core\Storage\Service\VideoPosterGenerator;
use Aurora\Core\Storage\StorageManager;
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
 * Backfills the still for every GED Document that still has `thumbnail_path`
 * NULL: a PDF's first page, a film's poster frame.
 *
 * Run after the migration that added the column, or after importing a
 * batch of docs through means other than the upload endpoint (e.g. a
 * data migration from another system). Idempotent - re-running won't
 * regenerate thumbs that already exist unless `--force` is passed.
 *
 * A film uploaded through the médiathèque already has its poster: the browser
 * drew it on the way in. This command is for the ones no browser handled, and
 * extracting a frame from a stored file needs `ffmpeg`. When it is not
 * installed the videos are reported as skipped and the PDFs still get done -
 * see {@see VideoPosterGenerator} for why it is not a requirement.
 */
#[AsCommand(
    name: 'aurora:ged:thumbnails:generate',
    description: 'Generate the missing thumbnails for PDF and video GED documents.',
)]
final class GenerateThumbnailsCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PdfThumbnailGenerator $thumbnailGenerator,
        private readonly VideoPosterGenerator $videoPosterGenerator,
        private readonly StorageManager $storageManager,
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
        $documents = $this->documentRepository->findBy([
            'mimeType' => [
                MimeTypeEnum::Pdf->value,
                MimeTypeEnum::Mp4->value,
                MimeTypeEnum::Webm->value,
            ],
        ]);

        if ([] === $documents) {
            $io->info('No PDF or video documents found.');

            return Command::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        // Asked once rather than per document: a library full of films and no
        // ffmpeg would otherwise print a warning per film for a binary nobody
        // ever promised to install. The films are skipped, said plainly, and
        // the PDFs in the same batch still get done.
        $canExtractFrames = $this->videoPosterGenerator->canExtract();

        if (!$canExtractFrames) {
            $io->note('ffmpeg is not installed: videos are skipped. Re-uploading one through the médiathèque produces its poster in the browser instead.');
        }

        foreach ($documents as $document) {
            $filePath = $document->getFilePath();
            if (null === $filePath) {
                continue;
            }

            if (!$force && null !== $document->getThumbnailPath()) {
                ++$skipped;
                continue;
            }

            $isVideo = MimeTypeEnum::tryFrom((string) $document->getMimeType())?->isVideo() ?? false;

            if ($isVideo && !$canExtractFrames) {
                ++$skipped;
                continue;
            }

            $thumbDirectory = $this->thumbDirFor($document);
            $basename = pathinfo($document->getFileName() ?? (string) $document->getId(), PATHINFO_FILENAME);
            $adapter = $this->storageManager->active();

            $thumbPath = $isVideo
                ? $this->videoPosterGenerator->fromSource($adapter, $filePath, $thumbDirectory, $basename)
                : $this->thumbnailGenerator->generate($adapter, $filePath, $thumbDirectory, $basename);

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
