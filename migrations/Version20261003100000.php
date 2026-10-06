<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Folders and notes of the same folder now share a single order.
 *
 * Until now each had its own, and the tree always showed folders first: a
 * note could not be placed before a folder. The two `position` columns stay,
 * but they are compared together.
 *
 * So that nothing moves on screen, each group of siblings (a folder, or the
 * root of a space) is renumbered the way it was displayed: its folders from 0
 * to F-1, in their order (position, then id), then its notes from F to F+N-1.
 * The trash is renumbered with the rest: a restore gets back a rank in the
 * same order.
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
        // Nothing to undo: the ranks stay valid for the old order, which
        // compared each kind separately in the same direction.
    }
}
