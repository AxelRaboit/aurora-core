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
 * Reopens the Drive tab of a space whose password is lost.
 *
 * **The lock's fallback, and its counterpart.** The password cannot be
 * turned off from the screen without being entered: that is what makes it a
 * lock rather than a speed bump, and it is also what makes forgetting it
 * blocking. So there must be a way out, and the right level of authority for
 * that way out is access to the server, not a checkbox in an interface.
 *
 * Written at the same time as the lock and not after: a door whose spare key
 * is written "later" is a door that ends up being forced.
 *
 * Does not touch open sessions: they fall away on their own, and the tab is
 * reopened for everyone anyway.
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
            // Said rather than done silently: someone running this command
            // believes the tab is locked, and picking the wrong space is the
            // most likely mistake.
            $io->warning(sprintf('L\'onglet Drive de « %s » n\'est pas fermé.', $space->getName()));

            return Command::SUCCESS;
        }

        // The lock and not `setDrivePassword(null)`: it also draws a new
        // generation, and it is the one that knows what clearing means.
        $this->lock->clear($space);
        $this->entityManager->flush();

        $io->success(sprintf('L\'onglet Drive de « %s » est rouvert.', $space->getName()));
        $io->note('Reposez un mot de passe depuis les réglages de l\'espace si vous en voulez un.');

        return Command::SUCCESS;
    }
}
