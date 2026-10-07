<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Sets the settings aside while the demonstration is rebuilt.
 *
 * **Rebuilding the demo data set erased the configuration.**
 * `make demo-reset` empties the database, and the settings live there: the
 * Google service account key, the Craft connection, the remote storage.
 * Everything pasted by hand once went away with the fake data, and one had to
 * go and find again a JSON file that Google does not hand out twice.
 *
 * What is kept is not decided by a list of keys - a list goes stale at the
 * first setting added. The rule is: **a setting is put back if it comes back
 * empty**. What the fixtures and the installation wrote therefore always
 * wins, and what nobody rewrites comes back by itself. A setting that points
 * at a demo row - the home page, the favicon - is rewritten by the fixtures,
 * and so is not put back on an identifier that has become wrong.
 *
 * **The values are copied as they are stored**, without going through the
 * service that decrypts them: a private key has no reason to exist in clear
 * on a disk, even for a second.
 *
 * The transit file is deleted after restoring.
 */
#[AsCommand(
    name: 'aurora:settings:preserve',
    description: 'Set the settings aside while the database is rebuilt, and put them back.',
)]
final class PreserveSettingsCommand extends Command
{
    private const string FILE = '/var/preserved-settings.json';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Read the settings and set them aside.')
            ->addOption('restore', null, InputOption::VALUE_NONE, 'Put back the ones that came back empty.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = dirname(__DIR__, 5).self::FILE;

        if ($input->getOption('dump')) {
            return $this->dump($io, $file);
        }

        if ($input->getOption('restore')) {
            return $this->restore($io, $file);
        }

        $io->error('Passez --dump ou --restore.');

        return Command::INVALID;
    }

    private function dump(SymfonyStyle $io, string $file): int
    {
        // The database does not exist yet on the very first `demo-reset`:
        // there is nothing to keep then, and that is not an error.
        try {
            // The whole row, not just the value: an integration's setting
            // does not exist until somebody has saved it, so it must be
            // possible to recreate it and not only to fill it.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT setting_key, value, description, setting_type, setting_group FROM core_settings WHERE value IS NOT NULL AND value <> :empty',
                ['empty' => ''],
            );
        } catch (Throwable) {
            $io->note('Aucune base à lire : rien à mettre de côté.');

            return Command::SUCCESS;
        }

        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $io->success(sprintf('%d réglage(s) mis de côté.', count($rows)));

        return Command::SUCCESS;
    }

    private function restore(SymfonyStyle $io, string $file): int
    {
        if (!file_exists($file)) {
            $io->note('Rien n\'avait été mis de côté.');

            return Command::SUCCESS;
        }

        $kept = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($kept)) {
            $io->warning('Le fichier de transit est illisible, il est ignoré.');

            return Command::SUCCESS;
        }

        $restored = 0;

        foreach ($kept as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!isset($row['setting_key'])) {
                continue;
            }

            $exists = (bool) $this->connection->fetchOne(
                'SELECT 1 FROM core_settings WHERE setting_key = :key',
                ['key' => $row['setting_key']],
            );

            // **Recreated when it has disappeared.** An integration's settings
            // - the Google key, the Craft connection - do not exist until
            // somebody has saved them: the installation does not seed them.
            // Doing only an `UPDATE` therefore put nothing back, and that is
            // what the first version did.
            if (!$exists) {
                $restored += $this->connection->insert('core_settings', [
                    'setting_key' => $row['setting_key'],
                    'value' => $row['value'],
                    'description' => $row['description'],
                    'setting_type' => $row['setting_type'],
                    'setting_group' => $row['setting_group'],
                ]);

                continue;
            }

            // **Otherwise, only if it came back empty.** What the fixtures or
            // the installation wrote is the current truth: an earlier value
            // pointing at a recreated row would point at the wrong one.
            $restored += $this->connection->executeStatement(
                'UPDATE core_settings SET value = :value WHERE setting_key = :key AND (value IS NULL OR value = :empty)',
                ['value' => $row['value'], 'key' => $row['setting_key'], 'empty' => ''],
            );
        }

        unlink($file);
        $io->success(sprintf('%d réglage(s) reposé(s), sur %d gardé(s).', $restored, count($kept)));

        return Command::SUCCESS;
    }
}
