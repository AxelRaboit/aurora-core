<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentRelocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Moves documents to one storage disk, from the console.
 *
 * The médiathèque already moves a document from its menu, one at a time or
 * the whole library; what a script needs is the same move for a known set: a
 * category, or the ids it has just imported. The public tour's screenshots
 * live on the server's own disk rather than on the object storage, by choice
 * (07/10/2026), and an import or a replacement always writes through the
 * active disk: this is the step that brings them back.
 *
 * Synchronous, through {@see DocumentRelocator}, the class the button and
 * the worker share: copy, verify, record, then delete at the source. A
 * document already where it is asked to be is left alone, so running it twice
 * is safe.
 */
#[AsCommand(
    name: 'aurora:ged:relocate',
    description: 'Move documents (a category, or ids) to one storage disk, the way the médiathèque does.',
)]
final class RelocateDocumentsCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentRelocator $relocator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('disk', InputArgument::REQUIRED, sprintf('Where the bytes go: %s.', implode(' or ', array_map(static fn (StorageDiskEnum $disk): string => $disk->value, StorageDiskEnum::cases()))));
        $this->addOption('category', null, InputOption::VALUE_REQUIRED, 'Every document of this category id.');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A document id; repeat the option for several.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would move and stop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = StorageDiskEnum::tryFrom((string) $input->getArgument('disk'));
        if (null === $target) {
            $io->error(sprintf('Unknown disk "%s".', (string) $input->getArgument('disk')));

            return Command::INVALID;
        }

        $documents = $this->documents($input);
        if (null === $documents) {
            $io->error('Say which documents: --category=<id> or --id=<id> (repeatable).');

            return Command::INVALID;
        }

        $toMove = array_values(array_filter($documents, static fn (DocumentInterface $document): bool => $document->getStorageDisk() !== $target));
        $io->writeln(sprintf('%d document(s), %d to move to "%s".', count($documents), count($toMove), $target->value));

        if ((bool) $input->getOption('dry-run')) {
            foreach ($toMove as $document) {
                $io->writeln(sprintf('  #%d %s (%s)', (int) $document->getId(), $document->getTitle(), $document->getStorageDisk()->value));
            }

            $io->info('Dry run: nothing moved.');

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($toMove as $document) {
            $relocation = $this->relocator->relocate($document, $target);
            if ($relocation->ok) {
                $io->writeln(sprintf('  moved #%d (%d file(s))', (int) $document->getId(), $relocation->filesMoved));

                continue;
            }

            ++$failed;
            $io->writeln(sprintf('  <error>#%d not moved: %s</error>', (int) $document->getId(), $relocation->busy ? 'already being moved' : ($relocation->error ?? 'unknown reason')));
        }

        if ($failed > 0) {
            $io->error(sprintf('%d document(s) could not be moved; they stay readable where they were.', $failed));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d document(s) on "%s".', count($documents), $target->value));

        return Command::SUCCESS;
    }

    /**
     * The documents asked for, or null when nothing was asked: a command that
     * moved the whole library because an option was forgotten would be the
     * worst way to find out.
     *
     * @return list<DocumentInterface>|null
     */
    private function documents(InputInterface $input): ?array
    {
        $category = $input->getOption('category');
        /** @var list<string> $ids */
        $ids = (array) $input->getOption('id');

        if (null === $category && [] === $ids) {
            return null;
        }

        $documents = [];
        if (null !== $category) {
            $documents = $this->documentRepository->findBy(['category' => (int) $category]);
        }

        if ([] !== $ids) {
            $documents = array_merge($documents, $this->documentRepository->findBy(['id' => array_map(intval(...), $ids)]));
        }

        $unique = [];
        foreach ($documents as $document) {
            $unique[(int) $document->getId()] = $document;
        }

        return array_values($unique);
    }
}
