<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Module\Ged\Document\Dto\DocumentInput;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\UnicodeString;
use Throwable;

/**
 * Puts files that are already on the server into the médiathèque.
 *
 * The médiathèque is filled through a browser, one drop at a time, and that is
 * the right way in for the handful of pictures an article needs. It is the
 * wrong way in for a folder of eighty photographs, for the contents of a
 * previous site being moved over, or for anything a script produced - and
 * until now those had no way in at all short of writing rows by hand, which
 * skips the reference, the variants, the version history and the audit trail,
 * and gets the storage disk wrong the day the installation stops writing to
 * the local one.
 *
 * So this takes exactly the same road as an upload: the uploader stores the
 * bytes through the active disk, the manager stamps the reference, builds the
 * responsive variants, records the first version and writes the audit line.
 * A document imported here is indistinguishable from one dropped in the
 * browser, which is the whole point.
 *
 * The title is the file's own name, tidied - `AXL00594-Modifier.jpg` becomes
 * `AXL00594 Modifier`. Alt text is deliberately **not** guessed: a filename
 * makes a passable title and a uselessly bad description, and a wrong alt is
 * worse than none because a screen reader reads it out with confidence.
 *
 * Nothing is deduplicated. Importing the same folder twice gives two
 * documents, because two identical files under different names are a
 * legitimate thing to hold and this command cannot tell that case from a
 * mistake.
 */
#[AsCommand(
    name: 'aurora:ged:import',
    description: 'Import local files into the médiathèque, the same way an upload would.',
)]
final class ImportDocumentsCommand extends Command
{
    public function __construct(
        private readonly GedDocumentUploader $uploader,
        private readonly DocumentManagerInterface $documentManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'paths',
            InputArgument::IS_ARRAY | InputArgument::REQUIRED,
            'Files to import. A directory is read one level deep, in name order.',
        );

        $this->addOption(
            'status',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Status of the imported documents (%s).', implode(', ', array_column(DocumentStatusEnum::cases(), 'value'))),
            DocumentStatusEnum::Published->value,
        );

        $this->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Id of the folder to file them under.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be imported and stop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importing files into the médiathèque');

        $status = DocumentStatusEnum::tryFrom((string) $input->getOption('status'));

        if (!$status instanceof DocumentStatusEnum) {
            $io->error(sprintf('Unknown status "%s".', (string) $input->getOption('status')));

            return Command::INVALID;
        }

        $folderOption = $input->getOption('folder');
        $folderId = is_numeric($folderOption) ? (int) $folderOption : null;

        /** @var list<string> $paths */
        $paths = (array) $input->getArgument('paths');
        $files = $this->collect($paths, $io);

        if ([] === $files) {
            $io->warning('Nothing to import.');

            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('dry-run')) {
            $io->listing($files);
            $io->info(sprintf('%d file(s) would be imported as %s.', count($files), $status->value));

            return Command::SUCCESS;
        }

        $imported = 0;
        $failed = 0;

        foreach ($files as $path) {
            // Test mode, because nothing here came through a POST: without it
            // UploadedFile insists the file was moved by PHP itself and
            // refuses to read a path the shell handed us.
            $file = new UploadedFile($path, basename($path), null, null, true);

            try {
                $metadata = $this->uploader->upload($file);
            } catch (Throwable $exception) {
                $io->warning(sprintf('%s: %s', basename($path), $exception->getMessage()));
                ++$failed;

                continue;
            }

            $document = $this->documentManager->create(new DocumentInput(
                title: $this->titleFrom($path),
                status: $status,
                filePath: $metadata['filePath'],
                fileName: $metadata['fileName'],
                originalName: $metadata['originalName'],
                mimeType: $metadata['mimeType'],
                size: $metadata['size'],
                width: $metadata['width'],
                height: $metadata['height'],
                thumbnailPath: $metadata['thumbnailPath'],
                folderId: $folderId,
            ));

            ++$imported;
            $io->writeln(sprintf('  ✓ #%d <info>%s</info> (%s)', $document->getId(), $document->getTitle(), basename($path)));
        }

        $io->success(sprintf('Done. Imported: %d  Failed: %d', $imported, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Files, in the order they were asked for, directories expanded in name
     * order so a numbered folder imports in its own order rather than in
     * whatever order the filesystem happens to answer in.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function collect(array $paths, SymfonyStyle $io): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (!is_dir($path)) {
                $io->warning(sprintf('%s: no such file or directory.', $path));

                continue;
            }

            $finder = new Finder()->files()->in($path)->depth(0)->sortByName();

            foreach ($finder as $file) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * The file's name, made readable: no extension, separators back to spaces,
     * and the runs of whitespace that leaves collapsed.
     *
     * Not slugged and not capitalised. `AXL00594` is a reference somebody
     * recognises and `axl00594` is not, so the case the photographer chose is
     * the case that stays.
     */
    private function titleFrom(string $path): string
    {
        $name = new UnicodeString(pathinfo($path, PATHINFO_FILENAME))
            ->replaceMatches('/[_\-]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim();

        return '' === $name->toString()
            ? new SplFileInfo($path)->getBasename()
            : $name->toString();
    }
}
