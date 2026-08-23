<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Command;

use Aurora\Core\Module\Service\PermissionRegistry;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps user privilege lists consistent with the registered module permissions.
 *
 * Run after deploying new modules or removing old ones:
 *
 *   php bin/console aurora:privileges:sync
 *
 * What it does
 *   - Collects the canonical set of privilege strings from every registered module.
 *   - Loads every user whose privilege list is non-empty.
 *   - Removes any privilege that is no longer declared in any module.
 *   - Flushes and reports a summary.
 */
#[AsCommand(
    name: 'aurora:privileges:sync',
    description: 'Purge obsolete privileges from users and report new ones available',
)]
final class SyncPrivilegesCommand extends Command
{
    public function __construct(
        private readonly PermissionRegistry $permissionRegistry,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $registered = $this->permissionRegistry->all();
        $registeredSet = array_flip($registered);

        $io->title('Privilege sync');
        $io->text(sprintf('<info>%d</info> registered privilege(s) across all modules.', count($registered)));

        $users = $this->userRepository->findAll();
        $purgedCount = 0;
        $usersAffected = 0;

        foreach ($users as $user) {
            $current = $user->getPrivileges();
            if ([] === $current) {
                continue;
            }

            $valid = array_values(array_filter($current, static fn (string $p): bool => isset($registeredSet[$p])));
            $removed = array_diff($current, $valid);

            if ([] !== $removed) {
                $user->setPrivileges($valid);
                $purgedCount += count($removed);
                ++$usersAffected;

                $io->text(sprintf(
                    '  <comment>%s</comment> - removed: %s',
                    $user->getEmail(),
                    implode(', ', $removed),
                ));
            }
        }

        if ($purgedCount > 0) {
            $this->entityManager->flush();
            $io->success(sprintf(
                'Purged %d obsolete privilege(s) from %d user(s).',
                $purgedCount,
                $usersAffected,
            ));
        } else {
            $io->success('All user privileges are up to date - nothing to purge.');
        }

        return Command::SUCCESS;
    }
}
