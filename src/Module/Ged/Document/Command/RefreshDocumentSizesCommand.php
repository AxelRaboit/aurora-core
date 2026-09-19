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
 * Remet le poids enregistré d'un document sur celui du fichier rangé.
 *
 * **Pourquoi ils divergent.** Le poids est relevé à l'arrivée du fichier, et
 * une source JPEG est ré-encodée en place à la qualité 85 - métadonnées
 * comprises - au moment où ses variantes sont fabriquées. Le nombre cessait
 * donc d'être vrai une ligne plus tard, et la médiathèque affichait un poids
 * sans rapport avec ce qui est stocké. Mesuré sur un import : un million et
 * demi d'octets annoncés pour deux cent mille sur le disque.
 *
 * {@see DocumentManager::regenerateVariantsIfImage()} le relit désormais après
 * coup, donc rien de nouveau n'entre faux. Cette commande est pour ce qui est
 * déjà là.
 *
 * Idempotente : la relancer sur une bibliothèque saine ne change rien et ne
 * dit rien. `--dry-run` compte sans écrire.
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
                // Un fichier absent n'est pas un poids à corriger, c'est un
                // autre problème - et l'écraser à zéro le cacherait.
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
