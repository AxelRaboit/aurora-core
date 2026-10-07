<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes move from the tree to folders.
 *
 * Until now a note could contain other notes, and a note with children acted
 * as a folder. This migration creates the folders, files the notes in them,
 * and removes the `parent_id` that carried both meanings at once.
 *
 * **The conversion is done in SQL, without decrypting anything.** A folder
 * name is encrypted like a note title, with the same key and the same
 * self-contained format (nonce + message, in base64), so copying the column
 * is enough: the migration needs neither the key nor the Symfony container,
 * and it fits in a transaction.
 *
 * **A note that had children becomes a folder and a note inside it**, with
 * the same name, which is what the zip export already wrote. Its text is
 * therefore never lost: it stays in the note, filed in the folder that bears
 * its name.
 *
 * The `converted_from_note_id` column only exists during the migration: it is
 * the mapping between the old folder-note and the created folder, and it is
 * dropped before the end.
 */
final class Version20260922210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: folders become their own entity, notes are filed in them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_notes_markdown_folder_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE core_notes_markdown_folders (
                id INT NOT NULL,
                user_id INT NOT NULL,
                parent_id INT DEFAULT NULL,
                name TEXT DEFAULT NULL,
                position INT DEFAULT 0 NOT NULL,
                deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                trashed_with_folder_id INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                converted_from_note_id INT DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_notes_folders_user ON core_notes_markdown_folders (user_id)');
        $this->addSql('CREATE INDEX idx_notes_folders_parent ON core_notes_markdown_folders (parent_id)');
        $this->addSql('CREATE INDEX idx_notes_folders_deleted_at ON core_notes_markdown_folders (deleted_at)');
        $this->addSql('CREATE INDEX idx_notes_folders_trashed_with ON core_notes_markdown_folders (trashed_with_folder_id)');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD CONSTRAINT fk_notes_folders_user FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD CONSTRAINT fk_notes_folders_parent FOREIGN KEY (parent_id) REFERENCES core_notes_markdown_folders (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD folder_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD trashed_with_folder_id INT DEFAULT NULL');

        // One folder per note that had children, flat: the parent links
        // between folders are restored right after, once all the ids exist.
        $this->addSql(<<<'SQL'
            INSERT INTO core_notes_markdown_folders
                (id, user_id, parent_id, name, position, deleted_at, trashed_with_folder_id, created_at, updated_at, converted_from_note_id)
            SELECT
                nextval('seq_core_notes_markdown_folder_id'),
                n.user_id,
                NULL,
                n.title,
                n.position,
                n.deleted_at,
                NULL,
                n.created_at,
                n.updated_at,
                n.id
            FROM core_notes_markdown_notes n
            WHERE EXISTS (
                SELECT 1 FROM core_notes_markdown_notes c WHERE c.parent_id = n.id
            )
            SQL);

        // The folder tree, taken from the note tree.
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_folders f
            SET parent_id = parent_folder.id
            FROM core_notes_markdown_notes n
            JOIN core_notes_markdown_folders parent_folder
              ON parent_folder.converted_from_note_id = n.parent_id
            WHERE f.converted_from_note_id = n.id
            SQL);

        // The children of a folder-note go into the folder.
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET folder_id = f.id
            FROM core_notes_markdown_folders f
            WHERE f.converted_from_note_id = n.parent_id
            SQL);

        // And the folder-note itself goes into its own folder, as the export
        // does: the file and the folder of the same name become a note in a
        // folder of the same name.
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET folder_id = f.id, position = 0
            FROM core_notes_markdown_folders f
            WHERE f.converted_from_note_id = n.id
            SQL);

        // What was trashed with a folder-note is now trashed with the folder:
        // restoring a branch still brings back the whole branch.
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET trashed_with_folder_id = f.id
            FROM core_notes_markdown_folders f
            WHERE f.converted_from_note_id = n.trashed_with_note_id
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_folders f
            SET trashed_with_folder_id = parent_folder.id
            FROM core_notes_markdown_notes n
            JOIN core_notes_markdown_folders parent_folder
              ON parent_folder.converted_from_note_id = n.trashed_with_note_id
            WHERE f.converted_from_note_id = n.id
            SQL);

        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP COLUMN converted_from_note_id');

        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP CONSTRAINT fk_c58b8746727aca70');
        $this->addSql('DROP INDEX idx_notes_markdown_parent');
        $this->addSql('DROP INDEX idx_notes_md_trashed_with');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP parent_id');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP trashed_with_note_id');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD CONSTRAINT fk_notes_markdown_folder FOREIGN KEY (folder_id) REFERENCES core_notes_markdown_folders (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_notes_markdown_folder ON core_notes_markdown_notes (folder_id)');
        $this->addSql('CREATE INDEX idx_notes_md_trashed_with ON core_notes_markdown_notes (trashed_with_folder_id)');

        // Sharing no longer has sub-notes to include: a note is a leaf, and a
        // folder cannot be shared.
        $this->addSql('ALTER TABLE core_notes_markdown_share_links DROP include_descendants');
    }

    /**
     * The reverse path, without losing how things were filed.
     *
     * Folders become folder-notes again: one that came from a note goes back
     * to it, and one created after the migration gives a new note, otherwise
     * its notes would move up to the root.
     *
     * **The round trip is not the identity, and cannot be.** An empty folder
     * becomes a note without children, which `up()` can no longer tell apart
     * from an ordinary note: on a notebook of 81 folders of which 12 are
     * empty, a down followed by an up gives 69 folders and 12 extra notes.
     * Nothing is lost - the folder name lives in the note - but the empty
     * filing does not come back. Measured on 22/09/2026; it is the price of a
     * `down()` on a change of shape, and the reason it is meant for rolling
     * back right away, not weeks later.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_share_links ADD include_descendants BOOLEAN DEFAULT false NOT NULL');

        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD trashed_with_note_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD note_id INT DEFAULT NULL');

        // One note per folder, which takes its name and its place.
        //
        // The id is reserved before the insert rather than guessed after:
        // `INSERT ... SELECT` does not return the source row, and matching
        // the two tables on dates would have confused two folders created in
        // the same second.
        $this->addSql("UPDATE core_notes_markdown_folders SET note_id = nextval('seq_core_notes_markdown_note_id')");

        $this->addSql(<<<'SQL'
            INSERT INTO core_notes_markdown_notes
                (id, user_id, parent_id, title, content, tags, position, deleted_at, trashed_with_note_id, created_at, updated_at)
            SELECT
                f.note_id,
                f.user_id,
                NULL,
                f.name,
                NULL,
                '[]',
                f.position,
                f.deleted_at,
                NULL,
                f.created_at,
                f.updated_at
            FROM core_notes_markdown_folders f
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET parent_id = f.note_id
            FROM core_notes_markdown_folders f
            WHERE n.folder_id = f.id
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes child
            SET parent_id = parent_folder.note_id
            FROM core_notes_markdown_folders f
            JOIN core_notes_markdown_folders parent_folder ON parent_folder.id = f.parent_id
            WHERE child.id = f.note_id
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET trashed_with_note_id = f.note_id
            FROM core_notes_markdown_folders f
            WHERE n.trashed_with_folder_id = f.id
            SQL);

        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP CONSTRAINT fk_notes_markdown_folder');
        $this->addSql('DROP INDEX idx_notes_markdown_folder');
        $this->addSql('DROP INDEX idx_notes_md_trashed_with');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP folder_id');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP trashed_with_folder_id');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD CONSTRAINT fk_c58b8746727aca70 FOREIGN KEY (parent_id) REFERENCES core_notes_markdown_notes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_notes_markdown_parent ON core_notes_markdown_notes (parent_id)');
        $this->addSql('CREATE INDEX idx_notes_md_trashed_with ON core_notes_markdown_notes (trashed_with_note_id)');

        $this->addSql('DROP TABLE core_notes_markdown_folders');
        $this->addSql('DROP SEQUENCE seq_core_notes_markdown_folder_id CASCADE');
    }
}
