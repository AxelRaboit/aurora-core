<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Command;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Rouvre l'onglet Drive d'un espace dont le mot de passe est perdu.
 *
 * **Le secours de la serrure, et sa contrepartie.** Le mot de passe ne se
 * désactive pas depuis l'écran sans être saisi : c'est ce qui en fait une
 * serrure plutôt qu'un ralentisseur, et c'est aussi ce qui rend l'oubli
 * bloquant. Il faut donc une sortie, et le bon niveau d'autorité pour cette
 * sortie est l'accès au serveur, pas une case dans une interface.
 *
 * Écrite en même temps que la serrure et non après : une porte dont on écrit
 * la clé de secours « plus tard » est une porte qu'on finit par forcer.
 *
 * Ne touche pas aux sessions ouvertes : elles retombent d'elles-mêmes, et
 * l'onglet est de toute façon rouvert pour tout le monde.
 */
#[AsCommand(
    name: 'aurora:space:drive-password:clear',
    description: 'Removes the password closing a space Drive tab, when it has been lost',
)]
final class ClearDrivePasswordCommand extends Command
{
    public function __construct(
        private readonly CustomerSpaceRepository $spaces,
        private readonly DriveLock $lock,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('space', InputArgument::REQUIRED, "L'identifiant de l'espace");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $space = $this->spaces->find((int) $input->getArgument('space'));

        if (!$space instanceof CustomerSpaceInterface) {
            $io->error(sprintf('Aucun espace numéro %s.', $input->getArgument('space')));

            return Command::FAILURE;
        }

        if (!$space->isDriveLocked()) {
            // Dit plutôt que fait silencieusement : quelqu'un qui lance cette
            // commande croit l'onglet fermé, et se tromper d'espace est
            // l'erreur la plus probable.
            $io->warning(sprintf('L\'onglet Drive de « %s » n\'est pas fermé.', $space->getName()));

            return Command::SUCCESS;
        }

        // La serrure et non `setDrivePassword(null)` : elle tire aussi une
        // nouvelle génération, et c'est elle qui sait ce qu'effacer veut dire.
        $this->lock->clear($space);
        $this->entityManager->flush();

        $io->success(sprintf('L\'onglet Drive de « %s » est rouvert.', $space->getName()));
        $io->note('Reposez un mot de passe depuis les réglages de l\'espace si vous en voulez un.');

        return Command::SUCCESS;
    }
}
