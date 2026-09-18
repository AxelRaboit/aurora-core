<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Module\Ged\Document\Dto\DocumentInput;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function is_file;
use function sprintf;

/**
 * Swaps the file behind a document that is already in the médiathèque.
 *
 * The one gesture the console could not make. Importing gives a *new*
 * document, and a new document is not what somebody wants when a picture is
 * already published: every page pointing at the old one would have to be
 * edited, the alt text and the caption retyped, and the old row trashed - four
 * chances to leave the site pointing at a screenshot that no longer shows what
 * it claims.
 *
 * Replacing in place keeps the row, so every page that shows this picture
 * shows the new one, and the old file becomes a version rather than nothing.
 * The address changes - the stored name is random on purpose - which is what
 * makes the change visible immediately rather than after somebody's cache
 * decides to let go.
 *
 * Everything else is deliberately carried over rather than reset: title,
 * description, status, category, tags, folder, alt, caption, focal point.
 * A replacement is a new file for the same document, and a command that
 * silently blanked the alt text of a published picture would be worse than
 * one that refuses to run.
 *
 * Same road as the browser's edit form: {@see GedDocumentUploader} writes the
 * bytes through the active disk, {@see DocumentManagerInterface::update()}
 * drops the old variants, builds the new ones, records the version and writes
 * the audit line.
 */
#[AsCommand(
    name: 'aurora:ged:replace',
    description: "Replace a document's file with a local one, the way the médiathèque's edit form does.",
)]
final class ReplaceDocumentFileCommand extends Command
{
    public function __construct(
        private readonly GedDocumentUploader $uploader,
        private readonly DocumentManagerInterface $documentManager,
        private readonly DocumentRepository $documentRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('document', InputArgument::REQUIRED, 'Id of the document whose file is replaced.');
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the file that takes its place.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say what would be replaced and stop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = (int) $input->getArgument('document');
        $path = (string) $input->getArgument('file');

        $document = $this->documentRepository->find($id);

        if (!$document instanceof DocumentInterface) {
            $io->error(sprintf('No document #%d.', $id));

            return Command::INVALID;
        }

        if (!is_file($path)) {
            $io->error(sprintf('No file at "%s".', $path));

            return Command::INVALID;
        }

        // Named before anything is written, because the id is the only thing
        // the caller passed and a wrong digit is the mistake to catch here
        // rather than in the médiathèque afterwards.
        $io->definitionList(
            ['Document' => sprintf('#%d %s', $id, $document->getTitle())],
            ['Fichier actuel' => (string) $document->getFilePath()],
            ['Remplacé par' => $path],
        );

        if ((bool) $input->getOption('dry-run')) {
            $io->info('Dry run: nothing written.');

            return Command::SUCCESS;
        }

        // Test mode, like the import beside it: nothing here came through a
        // POST, and UploadedFile otherwise insists the file was moved by PHP
        // itself and refuses to read a path the shell handed over.
        $file = new UploadedFile($path, basename($path), null, null, true);

        try {
            $metadata = $this->uploader->upload($file);
        } catch (Throwable $throwable) {
            $io->error(sprintf('Upload failed: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $this->documentManager->update($document, new DocumentInput(
            title: $document->getTitle(),
            description: $document->getDescription(),
            status: $document->getStatus(),
            categoryId: $document->getCategory()?->getId(),
            filePath: $metadata['filePath'],
            fileName: $metadata['fileName'],
            originalName: $metadata['originalName'],
            mimeType: $metadata['mimeType'],
            size: $metadata['size'],
            width: $metadata['width'],
            height: $metadata['height'],
            thumbnailPath: $metadata['thumbnailPath'],
            alt: $document->getAlt(),
            caption: $document->getCaption(),
            tagIds: $this->tagIdsOf($document),
            folderId: $document->getFolder()?->getId(),
            focalX: $document->getFocalX(),
            focalY: $document->getFocalY(),
        ));

        $io->success(sprintf(
            'Document #%d now carries %s. The previous file is kept as a version.',
            $id,
            (string) $document->getFilePath(),
        ));

        return Command::SUCCESS;
    }

    /** @return list<int> */
    private function tagIdsOf(DocumentInterface $document): array
    {
        $ids = [];

        foreach ($document->getTags() as $tag) {
            $tagId = $tag->getId();
            if (null !== $tagId) {
                $ids[] = $tagId;
            }
        }

        return $ids;
    }
}
