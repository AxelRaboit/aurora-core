<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Command;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

use function basename;
use function dirname;
use function sprintf;

/**
 * Takes back the note images left on the disk, and stores them in the active
 * storage.
 *
 * They were all there before 0.9.230: the module wrote to
 * `var/uploads/notes-markdown/{user}/` through a `Filesystem` used directly,
 * without going through the storage layer. Since then, it writes where
 * everything else goes, but what had already been pasted into a note did not
 * move on its own.
 *
 * **Idempotent, and it deletes nothing by default.** An image already present
 * in the active storage is left as it is; the original on the disk only goes
 * with `--purge`, in a second pass, once the notes have been reread. Two
 * steps rather than one, because a move that gets the key wrong loses images
 * that nothing regenerates.
 *
 * The path on the disk **is** the key, give or take the separators: both are
 * written `notes-markdown/{user}/{uuid}.ext`. The mapping is therefore
 * direct, and on purpose - the zone was declared in 0.9.188 with the value the
 * files already carried, so that nothing fails here.
 */
#[AsCommand(
    name: 'aurora:notes:images:adopt',
    description: 'Move markdown note images left on the local disk into the active storage.',
)]
final class AdoptNoteImagesCommand extends Command
{
    public function __construct(
        private readonly StorageManager $storageManager,
        #[Autowire('%kernel.project_dir%/var/uploads/notes-markdown')]
        private readonly string $legacyDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would move and stop.')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete the local original once it is in the active storage.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Images de notes restées sur le disque');

        if (!$this->filesystem->exists($this->legacyDir)) {
            $io->success("Rien à reprendre : le dossier local n'existe pas.");

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $purge = (bool) $input->getOption('purge');
        $adapter = $this->storageManager->active();

        $deplacees = 0;
        $deja = 0;
        $purgees = 0;

        $finder = new Finder()->files()->in($this->legacyDir)->depth('== 1')->sortByName();

        foreach ($finder as $file) {
            $utilisateur = basename(dirname($file->getPathname()));
            $key = sprintf('%s/%s/%s', StorageAreaEnum::NotesMarkdown->value, $utilisateur, $file->getFilename());

            if ($adapter->exists($key)) {
                ++$deja;

                if ($purge && !$dryRun) {
                    $this->filesystem->remove($file->getPathname());
                    ++$purgees;
                }

                continue;
            }

            $io->writeln(sprintf('  → %s', $key));
            ++$deplacees;

            if ($dryRun) {
                continue;
            }

            $adapter->writeFromLocalFile($key, $file->getPathname());

            if ($purge) {
                $this->filesystem->remove($file->getPathname());
                ++$purgees;
            }
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d image(s), %d déjà en place%s.',
            $dryRun ? 'À reprendre :' : 'Reprises :',
            $deplacees,
            $deja,
            $purge ? sprintf(', %d original(aux) supprimé(s)', $purgees) : '',
        ));

        return Command::SUCCESS;
    }
}
