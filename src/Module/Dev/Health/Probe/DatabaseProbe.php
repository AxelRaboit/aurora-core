<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Probe;

use Aurora\Core\Migration\Service\MigrationStatusChecker;
use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;
use Aurora\Module\Dev\Health\Report\HealthCheck;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Throwable;

/**
 * The database: does it answer, how fast, which version, how big, and is
 * the schema up to date.
 *
 * Pending migrations used to be shown in development only. In production
 * they are the one case where the code and the database disagree after a
 * deployment that stopped half way, and the case worth seeing most.
 */
final readonly class DatabaseProbe
{
    public function __construct(
        private Connection $connection,
        private MigrationStatusChecker $migrationStatusChecker,
    ) {}

    /** @return list<HealthCheck> */
    public function checks(): array
    {
        $started = hrtime(true);

        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (Throwable $throwable) {
            return [new HealthCheck('database', HealthStatusEnum::Danger, 'suite.health.database.label', 'suite.health.database.unreachable', [
                'error' => mb_substr($throwable->getMessage(), 0, 200),
            ])];
        }

        $milliseconds = (int) round((hrtime(true) - $started) / 1_000_000);
        $facts = [
            'pingMs' => $milliseconds,
            'version' => $this->serverVersion(),
            'sizeBytes' => $this->size(),
        ];

        $checks = [new HealthCheck(
            'database',
            $milliseconds > 500 ? HealthStatusEnum::Warning : HealthStatusEnum::Ok,
            'suite.health.database.label',
            'suite.health.database.answers',
            ['milliseconds' => $milliseconds],
            $facts,
        )];

        try {
            $pending = $this->migrationStatusChecker->countPending();
            $checks[] = new HealthCheck(
                'migrations',
                $pending > 0 ? HealthStatusEnum::Danger : HealthStatusEnum::Ok,
                'suite.health.migrations.label',
                $pending > 0 ? 'suite.health.migrations.pending' : 'suite.health.migrations.up_to_date',
                ['count' => $pending],
                ['pending' => $pending],
            );
        } catch (Throwable) {
            $checks[] = new HealthCheck('migrations', HealthStatusEnum::Unknown, 'suite.health.migrations.label', 'suite.health.migrations.unknown');
        }

        return $checks;
    }

    private function serverVersion(): ?string
    {
        try {
            $version = $this->connection->fetchOne('SHOW server_version');

            return is_string($version) ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** In bytes, on PostgreSQL; null elsewhere. */
    private function size(): ?int
    {
        try {
            if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                return null;
            }

            $size = $this->connection->fetchOne('SELECT pg_database_size(current_database())');

            return is_numeric($size) ? (int) $size : null;
        } catch (Throwable) {
            return null;
        }
    }
}
