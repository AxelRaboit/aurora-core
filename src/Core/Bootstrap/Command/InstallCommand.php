<?php

declare(strict_types=1);

namespace Aurora\Core\Bootstrap\Command;

use Aurora\Core\Bootstrap\BootstrapRunner;
use Aurora\Module\Platform\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the rows Aurora cannot run without, in any environment.
 *
 * Meant to run on every deploy, not once: it is idempotent by contract, so
 * adding a locale or a built-in post type in a later version reaches existing
 * installs through the same command rather than a migration nobody remembers
 * to write.
 *
 * It does not create an administrator. Shipping a known email and password to
 * production would be a hole rather than a convenience, so that stays a
 * deliberate act - `aurora:user:create` - which this command points at when it
 * finds no user.
 */
#[AsCommand(
    name: 'aurora:install',
    description: "Crée les données indispensables au fonctionnement d'Aurora (locales, thème, données de chaque module).",
)]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly BootstrapRunner $bootstrapRunner,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $created = 0;
        $failed = false;

        // Ordering and per-provider isolation live in the runner: the test
        // suite seeds through the same path, and two implementations of "what
        // an install does" is how they came to disagree.
        foreach ($this->bootstrapRunner->run() as $result) {
            if ($result->success) {
                $symfonyStyle->writeln(sprintf('  <info>+</info> %s', $result->label));
                ++$created;

                continue;
            }

            $symfonyStyle->writeln(sprintf('  <error>!</error> %s : %s', $result->label, $result->error));
            $failed = true;
        }

        if (0 === $created && !$failed) {
            $symfonyStyle->writeln('  <comment>=</comment> tout est déjà en place');
        }

        if ($failed) {
            $symfonyStyle->error('Certaines données obligatoires n\'ont pas pu être créées.');

            return Command::FAILURE;
        }

        $symfonyStyle->success(sprintf('%d élément(s) créé(s).', $created));

        $this->warnIfNoUser($symfonyStyle);

        return Command::SUCCESS;
    }

    /**
     * An install with no user is unusable - nobody can reach the backend - and
     * it is the one gap this command deliberately leaves, so it says so loudly
     * rather than reporting success on a site nobody can log into.
     */
    private function warnIfNoUser(SymfonyStyle $symfonyStyle): void
    {
        if ($this->entityManager->getRepository(User::class)->count([]) > 0) {
            return;
        }

        $symfonyStyle->warning([
            "Aucun utilisateur n'existe : le backend est inaccessible.",
            'Crée un administrateur avec : php bin/console aurora:user:create --admin',
        ]);
    }
}
