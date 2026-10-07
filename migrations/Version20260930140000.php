<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Note spaces.
 *
 * A note lived in its author's notebook, and sharing was added through a
 * date set on a folder or a note. It now lives in a space, which alone says
 * who reads it and who writes it.
 *
 * **Nothing becomes more visible than before.**
 *
 * - Each person who has notes gets their personal space, private, and
 *   everything they own moves into it as is: folders, positions, tags.
 * - A folder shared for reading becomes a space open to the whole
 *   back office, read-only, named after the folder, with everything it holds;
 *   it becomes its root. Those who read it still read it, and nothing
 *   more.
 * - A note shared on its own stays in the personal space: the date stays in
 *   the database, nothing reads it any more. Nobody else will see it from now.
 * - Pins move onto the person: one row per pinned note or folder, at the
 *   time it was pinned.
 *
 * **The author becomes optional**: deleting an account no longer deletes
 * what it wrote in a shared space. Its personal space goes with it, through
 * the space's cascade.
 *
 * The constraints on `user_id` are found by their column, not by their name:
 * two databases created at different times did not necessarily name them the
 * same.
 */
final class Version20260930140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: spaces with their own access, per-person favorites, authors that survive their account';
    }

    public function up(Schema $schema): void
    {
        // The tables.
        $this->addSql('CREATE SEQUENCE seq_core_notes_space_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_notes_space_member_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_notes_favorite_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql("CREATE TABLE core_notes_spaces (id INT NOT NULL, personal_user_id INT DEFAULT NULL, owner_id INT DEFAULT NULL, name TEXT DEFAULT NULL, color VARCHAR(7) DEFAULT NULL, access VARCHAR(16) DEFAULT 'private' NOT NULL, default_role VARCHAR(16) DEFAULT 'reader' NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, slug VARCHAR(120) DEFAULT NULL, indexable BOOLEAN DEFAULT false NOT NULL, position INT DEFAULT 0 NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A6D789A0989D9B62 ON core_notes_spaces (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A6D789A02449DABE ON core_notes_spaces (personal_user_id)');
        $this->addSql('CREATE INDEX idx_notes_spaces_owner ON core_notes_spaces (owner_id)');
        $this->addSql('CREATE INDEX idx_notes_spaces_access ON core_notes_spaces (access)');
        $this->addSql('CREATE INDEX idx_notes_spaces_deleted_at ON core_notes_spaces (deleted_at)');
        $this->addSql('ALTER TABLE core_notes_spaces ADD CONSTRAINT FK_A6D789A02449DABE FOREIGN KEY (personal_user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_spaces ADD CONSTRAINT FK_A6D789A07E3C61F9 FOREIGN KEY (owner_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql("CREATE TABLE core_notes_space_members (id INT NOT NULL, space_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(16) DEFAULT 'reader' NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_notes_space_member ON core_notes_space_members (space_id, user_id)');
        $this->addSql('CREATE INDEX IDX_C6B8D608A76ED395 ON core_notes_space_members (user_id)');
        $this->addSql('CREATE INDEX IDX_C6B8D60823575340 ON core_notes_space_members (space_id)');
        $this->addSql('ALTER TABLE core_notes_space_members ADD CONSTRAINT FK_C6B8D60823575340 FOREIGN KEY (space_id) REFERENCES core_notes_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_space_members ADD CONSTRAINT FK_C6B8D608A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE core_notes_favorites (id INT NOT NULL, user_id INT NOT NULL, note_id INT DEFAULT NULL, folder_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notes_favorite_note ON core_notes_favorites (user_id, note_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notes_favorite_folder ON core_notes_favorites (user_id, folder_id)');
        $this->addSql('CREATE INDEX IDX_38283790A76ED395 ON core_notes_favorites (user_id)');
        $this->addSql('CREATE INDEX IDX_3828379026ED0855 ON core_notes_favorites (note_id)');
        $this->addSql('CREATE INDEX IDX_38283790162CB942 ON core_notes_favorites (folder_id)');
        $this->addSql('ALTER TABLE core_notes_favorites ADD CONSTRAINT FK_38283790A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_favorites ADD CONSTRAINT FK_3828379026ED0855 FOREIGN KEY (note_id) REFERENCES core_notes_markdown_notes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_favorites ADD CONSTRAINT FK_38283790162CB942 FOREIGN KEY (folder_id) REFERENCES core_notes_markdown_folders (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Where each row lives: first without a constraint, while filling in.
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD space_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD space_id INT DEFAULT NULL');

        // A personal space for each person who has notes or folders, trash
        // included.
        $this->addSql(<<<'SQL'
            INSERT INTO core_notes_spaces (id, personal_user_id, owner_id, access, default_role, indexable, position, created_at, updated_at)
            SELECT nextval('seq_core_notes_space_id'), u.id, u.id, 'private', 'reader', false, 0, NOW(), NOW()
            FROM core_users u
            WHERE EXISTS (SELECT 1 FROM core_notes_markdown_notes n WHERE n.user_id = u.id)
               OR EXISTS (SELECT 1 FROM core_notes_markdown_folders f WHERE f.user_id = u.id)
            SQL);
        $this->addSql('UPDATE core_notes_markdown_folders f SET space_id = s.id FROM core_notes_spaces s WHERE s.personal_user_id = f.user_id');

        // Each folder shared for reading, the highest of its branch, becomes a
        // space open to the whole back office, read-only.
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE tmp_notes_shared_roots AS
            SELECT f.id AS folder_id, nextval('seq_core_notes_space_id') AS space_id, f.user_id, f.name
            FROM core_notes_markdown_folders f
            WHERE f.shared_at IS NOT NULL
              AND f.deleted_at IS NULL
              AND NOT EXISTS (
                  WITH RECURSIVE ancestors AS (
                      SELECT p.id, p.parent_id, p.shared_at FROM core_notes_markdown_folders p WHERE p.id = f.parent_id
                      UNION ALL
                      SELECT q.id, q.parent_id, q.shared_at FROM core_notes_markdown_folders q JOIN ancestors a ON q.id = a.parent_id
                  )
                  SELECT 1 FROM ancestors WHERE ancestors.shared_at IS NOT NULL
              )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO core_notes_spaces (id, owner_id, name, access, default_role, indexable, position, created_at, updated_at)
            SELECT space_id, user_id, name, 'backoffice', 'reader', false, 0, NOW(), NOW() FROM tmp_notes_shared_roots
            SQL);
        $this->addSql(<<<'SQL'
            WITH RECURSIVE branch AS (
                SELECT r.folder_id AS id, r.space_id FROM tmp_notes_shared_roots r
                UNION ALL
                SELECT c.id, b.space_id FROM core_notes_markdown_folders c JOIN branch b ON c.parent_id = b.id
            )
            UPDATE core_notes_markdown_folders f SET space_id = branch.space_id FROM branch WHERE f.id = branch.id
            SQL);
        $this->addSql('UPDATE core_notes_markdown_folders SET parent_id = NULL WHERE id IN (SELECT folder_id FROM tmp_notes_shared_roots)');
        $this->addSql('DROP TABLE tmp_notes_shared_roots');

        // A note in a folder takes its folder's space; a note at the root, its
        // author's.
        $this->addSql('UPDATE core_notes_markdown_notes n SET space_id = f.space_id FROM core_notes_markdown_folders f WHERE n.folder_id = f.id');
        $this->addSql('UPDATE core_notes_markdown_notes n SET space_id = s.id FROM core_notes_spaces s WHERE n.space_id IS NULL AND s.personal_user_id = n.user_id');

        // Pins, on the person.
        $this->addSql("INSERT INTO core_notes_favorites (id, user_id, note_id, created_at) SELECT nextval('seq_core_notes_favorite_id'), n.user_id, n.id, n.favorited_at FROM core_notes_markdown_notes n WHERE n.favorited_at IS NOT NULL");
        $this->addSql("INSERT INTO core_notes_favorites (id, user_id, folder_id, created_at) SELECT nextval('seq_core_notes_favorite_id'), f.user_id, f.id, f.favorited_at FROM core_notes_markdown_folders f WHERE f.favorited_at IS NOT NULL");

        // Every row now has its space.
        $this->addSql('ALTER TABLE core_notes_markdown_folders ALTER space_id SET NOT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ALTER space_id SET NOT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD CONSTRAINT FK_834B380523575340 FOREIGN KEY (space_id) REFERENCES core_notes_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD CONSTRAINT FK_C58B874623575340 FOREIGN KEY (space_id) REFERENCES core_notes_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_834B380523575340 ON core_notes_markdown_folders (space_id)');
        $this->addSql('CREATE INDEX IDX_C58B874623575340 ON core_notes_markdown_notes (space_id)');

        // The author outlives their account: the key becomes SET NULL.
        foreach (['core_notes_markdown_folders' => 'FK_834B3805A76ED395', 'core_notes_markdown_notes' => 'FK_C58B8746A76ED395'] as $table => $name) {
            $this->addSql($this->dropForeignKeyOn($table, 'user_id'));
            $this->addSql(sprintf('ALTER TABLE %s ALTER user_id DROP NOT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE', $table, $name));
        }
    }

    public function down(Schema $schema): void
    {
        // Rows without an author cannot go back to a mandatory key: they are
        // handed to their space's owner, when there is one.
        foreach (['core_notes_markdown_folders' => 'FK_834B3805A76ED395', 'core_notes_markdown_notes' => 'FK_C58B8746A76ED395'] as $table => $name) {
            $this->addSql(sprintf('UPDATE %s t SET user_id = s.owner_id FROM core_notes_spaces s WHERE t.user_id IS NULL AND t.space_id = s.id', $table));
            $this->addSql(sprintf('DELETE FROM %s WHERE user_id IS NULL', $table));
            $this->addSql($this->dropForeignKeyOn($table, 'user_id'));
            $this->addSql(sprintf('ALTER TABLE %s ALTER user_id SET NOT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE', $table, $name));
        }

        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP CONSTRAINT FK_834B380523575340');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP CONSTRAINT FK_C58B874623575340');
        $this->addSql('DROP INDEX IDX_834B380523575340');
        $this->addSql('DROP INDEX IDX_C58B874623575340');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP space_id');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP space_id');
        $this->addSql('DROP TABLE core_notes_favorites');
        $this->addSql('DROP TABLE core_notes_space_members');
        $this->addSql('DROP TABLE core_notes_spaces');
        $this->addSql('DROP SEQUENCE seq_core_notes_favorite_id');
        $this->addSql('DROP SEQUENCE seq_core_notes_space_member_id');
        $this->addSql('DROP SEQUENCE seq_core_notes_space_id');
    }

    /**
     * Drops the foreign key carried by a column, whatever its name.
     */
    private function dropForeignKeyOn(string $table, string $column): string
    {
        return sprintf(<<<'SQL'
            DO $$
            DECLARE constraint_name text;
            BEGIN
                FOR constraint_name IN
                    SELECT c.conname FROM pg_constraint c
                    JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
                    WHERE c.conrelid = '%1$s'::regclass AND c.contype = 'f' AND a.attname = '%2$s'
                LOOP
                    EXECUTE format('ALTER TABLE %1$s DROP CONSTRAINT %%I', constraint_name);
                END LOOP;
            END $$
            SQL, $table, $column);
    }
}
