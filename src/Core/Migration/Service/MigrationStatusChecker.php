<?php

declare(strict_types=1);

namespace Aurora\Core\Migration\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function count;

/**
 * Lightweight migration-state checker. Compares the number of available
 * migration files on disk (`migrations/Version*.php`) with the number of
 * rows in the `doctrine_migration_versions` table.
 *
 * Used to surface a clear warning when a developer's dev database has
 * pending migrations — the symptom that bit us when the privileges
 * modal kept showing `core.media.*` labels after the Jalon 5 rename
 * because the user hadn't run `make migrate` on their dev DB.
 *
 * Designed to be cheap (one SELECT COUNT(*) + a glob) so it's safe to
 * call on every admin response in dev for the banner. Result is cached
 * in-instance so multiple calls per request don't multiply the query.
 */
final class MigrationStatusChecker
{
    private ?int $pendingCount = null;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    /**
     * Number of migration files present on disk that have not been
     * executed yet on the connected database. Returns 0 when up-to-date.
     */
    public function countPending(): int
    {
        if (null !== $this->pendingCount) {
            return $this->pendingCount;
        }

        $available = $this->countAvailableMigrationFiles();
        $executed = $this->countExecutedMigrations();

        return $this->pendingCount = max(0, $available - $executed);
    }

    public function hasPending(): bool
    {
        return $this->countPending() > 0;
    }

    private function countAvailableMigrationFiles(): int
    {
        $files = glob($this->projectDir.'/migrations/Version*.php');

        return false === $files ? 0 : count($files);
    }

    private function countExecutedMigrations(): int
    {
        // The migrations table may not exist yet on a brand-new DB.
        // Treat that as "everything is pending" — which is technically
        // accurate and surfaces the right call-to-action.
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
        } catch (Throwable) {
            return 0;
        }
    }
}
