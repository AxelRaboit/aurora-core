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
 * Reprend les images de notes restées sur le disque, et les range dans le
 * stockage actif.
 *
 * Elles y étaient toutes avant la 0.9.230 : le module écrivait dans
 * `var/uploads/notes-markdown/{utilisateur}/` par un `Filesystem` posé en
 * direct, sans passer par la couche de stockage. Depuis, il écrit là où va
 * tout le reste, mais ce qui avait déjà été collé dans une note ne s'est pas
 * déplacé tout seul.
 *
 * **Idempotente, et elle ne supprime rien par défaut.** Une image déjà
 * présente dans le stockage actif est laissée telle quelle ; l'original sur
 * le disque ne part qu'avec `--purge`, dans un second passage, une fois que
 * les notes ont été relues. Deux temps plutôt qu'un, parce qu'un déplacement
 * qui se trompe de clé perd des images que rien ne régénère.
 *
 * Le chemin sur le disque **est** la clé, aux séparateurs près : les deux
 * s'écrivent `notes-markdown/{utilisateur}/{uuid}.ext`. La correspondance est
 * donc directe, et c'est voulu - la zone a été déclarée en 0.9.188 avec la
 * valeur que les fichiers portaient déjà, pour que rien n'échoue ici.
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
