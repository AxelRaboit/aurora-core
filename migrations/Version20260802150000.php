<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Menu entries stop taking their children with them, and the Editorial
 * indexes get the names the entities now declare.
 *
 * The foreign key is the load-bearing half. MenuManager::deleteItem()
 * promotes an entry's children to their grandparent before removing it - an
 * editor deleting a heading means "drop this label", not "drop the six links
 * under it" - and `ON DELETE CASCADE` had the database undo that promotion
 * immediately afterwards. `AbstractTaxonomyTerm` already had the right shape;
 * this brings the menu tree in line with it.
 *
 * The renames are cosmetic: constraints Doctrine had auto-named are now
 * declared explicitly, so they read as themselves in psql.
 */
final class Version20260802150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Menu items orphan their children instead of cascading; name the Editorial indexes explicitly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_menu_items DROP CONSTRAINT fk_4cfe4ecb727aca70');
        $this->addSql('ALTER TABLE core_menu_items ADD CONSTRAINT FK_4CFE4ECB727ACA70 FOREIGN KEY (parent_id) REFERENCES core_menu_items (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER INDEX idx_post_slug_history_post RENAME TO IDX_C04809954B89032C');
        $this->addSql('ALTER INDEX uniq_6a82ae684b89032c4180c698 RENAME TO uniq_post_translation_locale');
        $this->addSql('ALTER INDEX uniq_9e598575e2c35fc4180c698 RENAME TO uniq_taxonomy_term_translation_locale');
        $this->addSql('ALTER INDEX uniq_5ee2037b9557e6f64180c698 RENAME TO uniq_taxonomy_translation_locale');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX uniq_taxonomy_translation_locale RENAME TO uniq_5ee2037b9557e6f64180c698');
        $this->addSql('ALTER INDEX uniq_taxonomy_term_translation_locale RENAME TO uniq_9e598575e2c35fc4180c698');
        $this->addSql('ALTER INDEX uniq_post_translation_locale RENAME TO uniq_6a82ae684b89032c4180c698');
        $this->addSql('ALTER INDEX IDX_C04809954B89032C RENAME TO idx_post_slug_history_post');

        $this->addSql('ALTER TABLE core_menu_items DROP CONSTRAINT FK_4CFE4ECB727ACA70');
        $this->addSql('ALTER TABLE core_menu_items ADD CONSTRAINT fk_4cfe4ecb727aca70 FOREIGN KEY (parent_id) REFERENCES core_menu_items (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
