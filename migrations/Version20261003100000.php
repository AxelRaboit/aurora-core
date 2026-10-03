<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dossiers et notes d'un même dossier partagent désormais un seul ordre.
 *
 * Jusqu'ici chacun avait le sien, et l'arborescence montrait toujours les
 * dossiers d'abord : on ne pouvait pas ranger une note avant un dossier. Les
 * deux colonnes `position` restent, mais elles se comparent ensemble.
 *
 * Pour que rien ne bouge à l'écran, chaque groupe de frères (un dossier, ou la
 * racine d'un espace) est renuméroté comme il s'affichait : ses dossiers de 0
 * à F-1, dans leur ordre (position, puis id), puis ses notes de F à F+N-1.
 * La corbeille est renumérotée avec le reste : une restauration retrouve un
 * rang du même ordre.
 */
final class Version20261003100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: one shared order for the folders and notes of a folder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_folders f
            SET position = ranked.rank
            FROM (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY space_id, COALESCE(parent_id, 0) ORDER BY position, id) - 1 AS rank
                FROM core_notes_markdown_folders
            ) ranked
            WHERE f.id = ranked.id
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET position = ranked.rank + COALESCE(folders.total, 0)
            FROM (
                SELECT id, space_id, folder_id,
                       ROW_NUMBER() OVER (PARTITION BY space_id, COALESCE(folder_id, 0) ORDER BY position, id) - 1 AS rank
                FROM core_notes_markdown_notes
            ) ranked
            LEFT JOIN (
                SELECT space_id, COALESCE(parent_id, 0) AS parent_key, COUNT(*) AS total
                FROM core_notes_markdown_folders
                GROUP BY space_id, COALESCE(parent_id, 0)
            ) folders ON folders.space_id = ranked.space_id AND folders.parent_key = COALESCE(ranked.folder_id, 0)
            WHERE n.id = ranked.id
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : les rangs restent valables pour l'ancien ordre, qui
        // comparait chaque nature à part dans le même sens.
    }
}
