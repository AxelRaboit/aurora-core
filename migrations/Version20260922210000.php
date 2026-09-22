<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les notes passent de l'arbre aux dossiers.
 *
 * Jusqu'ici une note pouvait en contenir d'autres, et une note qui avait des
 * enfants tenait lieu de dossier. Cette migration crée les dossiers, y range
 * les notes, et retire le `parent_id` qui portait les deux sens à la fois.
 *
 * **La conversion se fait en SQL, sans déchiffrer quoi que ce soit.** Le nom
 * d'un dossier est chiffré comme le titre d'une note, avec la même clé et le
 * même format autonome (nonce + message, en base64), donc copier la colonne
 * suffit : la migration n'a besoin ni de la clé ni du conteneur Symfony, et
 * elle tient dans une transaction.
 *
 * **Une note qui avait des enfants devient un dossier et une note dedans**, du
 * même nom, ce que l'export en zip écrivait déjà. Son texte n'est donc jamais
 * perdu : il reste dans la note, rangée dans le dossier qui porte son nom.
 *
 * La colonne `converted_from_note_id` n'existe que le temps de la migration :
 * c'est la table de correspondance entre l'ancienne note-dossier et le
 * dossier créé, et elle disparaît avant la fin.
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

        // Un dossier par note qui avait des enfants, à plat : les liens de
        // parenté entre dossiers sont rétablis juste après, quand tous les
        // identifiants existent.
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

        // L'arborescence des dossiers, reprise de celle des notes.
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_folders f
            SET parent_id = parent_folder.id
            FROM core_notes_markdown_notes n
            JOIN core_notes_markdown_folders parent_folder
              ON parent_folder.converted_from_note_id = n.parent_id
            WHERE f.converted_from_note_id = n.id
            SQL);

        // Les enfants d'une note-dossier entrent dans le dossier.
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET folder_id = f.id
            FROM core_notes_markdown_folders f
            WHERE f.converted_from_note_id = n.parent_id
            SQL);

        // Et la note-dossier elle-même entre dans le sien, comme le fait
        // l'export : le fichier et le dossier du même nom deviennent une note
        // dans un dossier du même nom.
        $this->addSql(<<<'SQL'
            UPDATE core_notes_markdown_notes n
            SET folder_id = f.id, position = 0
            FROM core_notes_markdown_folders f
            WHERE f.converted_from_note_id = n.id
            SQL);

        // Ce qui était tombé avec une note-dossier tombe désormais avec le
        // dossier : la restauration d'une branche continue de rendre la
        // branche entière.
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

        // Le partage n'a plus de sous-notes à inclure : une note est une
        // feuille, et un dossier ne se partage pas.
        $this->addSql('ALTER TABLE core_notes_markdown_share_links DROP include_descendants');
    }

    /**
     * Le chemin inverse, sans perte de rangement.
     *
     * Les dossiers redeviennent des notes-dossiers : celui qui vient d'une
     * note y retourne, et celui créé après la migration donne une note
     * nouvelle, sinon ses notes remonteraient à la racine.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_share_links ADD include_descendants BOOLEAN DEFAULT false NOT NULL');

        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD trashed_with_note_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD note_id INT DEFAULT NULL');

        // Une note par dossier, qui reprend son nom et sa place.
        //
        // L'identifiant est réservé avant l'insertion plutôt que deviné
        // après : `INSERT ... SELECT` ne rend pas la ligne source, et
        // rapprocher les deux tables sur les dates aurait confondu deux
        // dossiers créés dans la même seconde.
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
