<?php

declare(strict_types=1);

namespace Aurora\Core\Bootstrap\Command;

use Aurora\Core\Bootstrap\BootstrapProviderInterface;
use Aurora\Module\Platform\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

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
 * deliberate act — `aurora:user:create` — which this command points at when it
 * finds no user.
 */
#[AsCommand(
    name: 'aurora:install',
    description: "Crée les données indispensables au fonctionnement d'Aurora (locales, thème, données de chaque module).",
)]
final class InstallCommand extends Command
{
    /**
     * @param iterable<BootstrapProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('aurora.bootstrap_provider')]
        private readonly iterable $providers,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $providers = iterator_to_array($this->providers, false);
        usort(
            $providers,
            static fn (BootstrapProviderInterface $a, BootstrapProviderInterface $b): int => $b->getPriority() <=> $a->getPriority(),
        );

        $created = 0;
        $failed = false;

        foreach ($providers as $provider) {
            $name = new ReflectionClass($provider)->getShortName();

            try {
                foreach ($provider->bootstrap() as $label) {
                    $symfonyStyle->writeln(sprintf('  <info>+</info> %s', $label));
                    ++$created;
                }
            } catch (Throwable $e) {
                // One module's seed failing must not hide the others: a deploy
                // needs to see everything that is wrong, not just the first.
                $symfonyStyle->writeln(sprintf('  <error>!</error> %s : %s', $name, $e->getMessage()));
                $failed = true;
            }
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
     * An install with no user is unusable — nobody can reach the backend — and
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
