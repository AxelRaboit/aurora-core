<?php

declare(strict_types=1);

namespace Aurora\Core\Sequence;

use Doctrine\DBAL\Connection;

/**
 * Atomic sequential number generator backed by a PostgreSQL table.
 *
 * Each logical series is a row in `app_sequence_counters` (prefix, year):
 *   - global  (year = 0):    ORD-000001, LOG-000031
 *   - yearly  (year = YYYY): FAC-2026-0001
 *
 * Increments use INSERT … ON CONFLICT DO UPDATE RETURNING - a single atomic
 * statement that requires no explicit lock or transaction and handles the
 * first-use (INSERT) and subsequent uses (UPDATE) transparently.
 *
 * Advantages over PostgreSQL sequences:
 *   - Fully managed by Doctrine migrations (no schema drift)
 *   - No schema_filter workarounds needed
 *   - Resilient to DB resets (data survives in the table)
 *   - Works identically across PostgreSQL versions
 */
final readonly class SequenceGenerator
{
    public function __construct(private Connection $connection) {}

    /**
     * Yearly sequence - resets to 1 each calendar year.
     * Format: {PREFIX}-{YEAR}-{NNNN}.
     */
    public function nextYearly(string $prefix, int $year, int $pad = 4): string
    {
        $next = $this->increment($prefix, $year);

        return sprintf('%s-%d-%0'.$pad.'d', $prefix, $year, $next);
    }

    /**
     * Global sequence - never resets.
     * Format: {PREFIX}-{NNNNNN}.
     */
    public function next(string $prefix, int $pad = 6): string
    {
        $next = $this->increment($prefix, 0);

        return sprintf('%s-%0'.$pad.'d', $prefix, $next);
    }

    /**
     * Return the current value without consuming it.
     * Returns 0 if the series has never been used.
     */
    public function current(string $prefix, ?int $year = null): int
    {
        $result = $this->connection->fetchOne(
            'SELECT last_value FROM app_sequence_counters WHERE prefix = ? AND year = ?',
            [$prefix, $year ?? 0],
        );

        return false === $result ? 0 : (int) $result;
    }

    private function increment(string $prefix, int $year): int
    {
        $result = $this->connection->executeQuery(
            'INSERT INTO app_sequence_counters (prefix, year, last_value)
             VALUES (:prefix, :year, 1)
             ON CONFLICT (prefix, year)
             DO UPDATE SET last_value = app_sequence_counters.last_value + 1
             RETURNING last_value',
            ['prefix' => $prefix, 'year' => $year],
        );

        return (int) $result->fetchOne();
    }
}
