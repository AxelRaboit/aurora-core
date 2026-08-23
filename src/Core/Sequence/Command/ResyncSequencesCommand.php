<?php

declare(strict_types=1);

namespace Aurora\Core\Sequence\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Resyncs all named PostgreSQL sequences (seq_*_id) to MAX(id)+1.
 *
 * Needed after data imports, fixture loads, or whenever the Doctrine
 * SEQUENCE strategy drifts from the actual table contents.
 */
#[AsCommand(
    name: 'aurora:sequences:resync',
    description: 'Resync all PostgreSQL sequences to MAX(id)+1 for each table.',
)]
final class ResyncSequencesCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Resyncing PostgreSQL sequences');

        // Fetch all named sequences matching seq_*_id
        $sequences = $this->connection->fetchFirstColumn(
            "SELECT sequencename FROM pg_sequences
             WHERE schemaname = 'public' AND sequencename ~ '^seq_.+_id$'
             ORDER BY sequencename",
        );

        // Map sequence name → table name by stripping seq_ prefix and _id suffix
        $synced = 0;
        $skipped = 0;

        foreach ($sequences as $seqName) {
            $tableName = $this->resolveTableName($seqName);
            if (null === $tableName) {
                $io->note(sprintf('Skipping %s - table not found', $seqName));
                ++$skipped;
                continue;
            }

            try {
                $maxId = (int) $this->connection->fetchOne(
                    sprintf('SELECT COALESCE(MAX(id), 0) FROM %s', $tableName),
                );
                $this->connection->executeQuery(
                    sprintf("SELECT setval('%s', :next, false)", $seqName),
                    ['next' => $maxId + 1],
                );
                $io->writeln(sprintf('  <info>%-40s</info> → %d', $seqName, $maxId + 1));
                ++$synced;
            } catch (Throwable $e) {
                $io->warning(sprintf('Failed %s: %s', $seqName, $e->getMessage()));
                ++$skipped;
            }
        }

        $io->success(sprintf('%d sequence(s) resynced, %d skipped.', $synced, $skipped));

        return Command::SUCCESS;
    }

    private function resolveTableName(string $seqName): ?string
    {
        // seq_foo_bar_id → foo_bar (strip seq_ prefix and _id suffix)
        $slug = preg_replace('/^seq_/', '', preg_replace('/_id$/', '', $seqName) ?? '') ?? '';

        // Ask PostgreSQL for any table that ends with the slug (handles module prefixes)
        // and also try common English plural forms: +s, y→ies
        $slugVariants = array_unique([$slug, $slug.'s', $slug.'es']);
        if (str_ends_with($slug, 'y')) {
            $slugVariants[] = mb_substr($slug, 0, -1).'ies';
        }

        foreach ($slugVariants as $variant) {
            // Prefer exact match, then prefixed (e.g. crm_companies)
            // ORDER BY length ensures shorter (more exact) names win over pivot tables
            $match = $this->connection->fetchOne(
                "SELECT t.table_name FROM information_schema.tables t
                 WHERE t.table_schema = 'public'
                   AND (t.table_name = ? OR t.table_name LIKE '%_' || ?)
                   AND t.table_type = 'BASE TABLE'
                   AND EXISTS (SELECT 1 FROM information_schema.columns c
                               WHERE c.table_schema = 'public'
                                 AND c.table_name = t.table_name
                                 AND c.column_name = 'id')
                 ORDER BY length(t.table_name)
                 LIMIT 1",
                [$variant, $variant],
            );
            if ($match) {
                return $match;
            }
        }

        return null;
    }
}
