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
 * Met les réglages de côté le temps de reconstruire la démonstration.
 *
 * **Reconstruire le jeu de démonstration effaçait la configuration.**
 * `make demo-reset` vide la base, et les réglages y vivent : la clé du compte
 * de service Google, la connexion Craft, le stockage distant. Tout ce qu'on a
 * collé à la main une fois repartait avec les fausses données, et il fallait
 * retourner chercher un fichier JSON que Google ne redonne pas.
 *
 * Ce qu'on garde n'est pas décidé par une liste de clés - une liste se périme
 * au premier réglage ajouté. La règle est : **un réglage repose s'il revient
 * vide**. Ce que les fixtures et l'installation ont écrit gagne donc toujours,
 * et ce que personne ne réécrit revient de lui-même. Un réglage qui pointe une
 * ligne de démonstration - la page d'accueil, le favori - est réécrit par les
 * fixtures, et ne repose donc pas sur un identifiant devenu faux.
 *
 * **Les valeurs sont recopiées telles qu'elles sont stockées**, sans passer par
 * le service qui les déchiffre : une clé privée n'a pas à exister en clair sur
 * un disque, fût-ce une seconde.
 *
 * Le fichier de transit est supprimé après restitution.
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
        // La base n'existe pas encore au tout premier `demo-reset` : il n'y a
        // alors rien à garder, et ce n'est pas une erreur.
        try {
            // La ligne entière, et non la seule valeur : un réglage d'une
            // intégration n'existe pas tant que personne ne l'a enregistré,
            // donc il faut pouvoir le recréer et pas seulement le remplir.
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

            // **Recréé quand il a disparu.** Les réglages d'une intégration -
            // la clé Google, la connexion Craft - n'existent pas tant que
            // personne ne les a enregistrés : l'installation ne les sème pas.
            // Ne faire qu'un `UPDATE` ne remettait donc rien, et c'est ce que
            // la première version faisait.
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

            // **Sinon, seulement s'il est revenu vide.** Ce que les fixtures ou
            // l'installation ont écrit est la vérité du moment : une valeur
            // d'avant qui pointe une ligne recréée pointerait à côté.
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
