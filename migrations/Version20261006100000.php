<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trame categories become rows the studio manages, instead of an enum.
 *
 * The enum named three trades - community management, photography, web
 * development - written into a bundle anybody installs, where decks and
 * deliverables already had categories one creates from the screen.
 *
 * Nothing filed is lost: each trade some trame still uses becomes a category
 * of that name, in the French the screen showed it under, and its trames move
 * to it. A trade nobody used creates nothing, so a fresh installation starts
 * with no category at all.
 */
final class Version20261006100000 extends AbstractMigration
{
    /** What the screen called each trade, in the order it listed them. */
    private const array TRADES = [
        'community_management' => 'CM',
        'photography' => 'Photographie',
        'development' => 'Développement web',
    ];

    public function getDescription(): string
    {
        return 'Contract templates: categories are managed rows, the trades enum is converted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_contract_template_category_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_contract_template_categories (id INT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE core_contract_templates ADD category_id INT DEFAULT NULL');

        $position = 0;
        foreach (self::TRADES as $value => $name) {
            ++$position;
            $this->addSql(
                "INSERT INTO core_contract_template_categories (id, name, color, position, created_at, updated_at)
                 SELECT nextval('seq_core_contract_template_category_id'), :name, NULL, :position, NOW(), NOW()
                 WHERE EXISTS (SELECT 1 FROM core_contract_templates WHERE category = :value)",
                ['name' => $name, 'position' => $position, 'value' => $value],
            );
            $this->addSql(
                'UPDATE core_contract_templates SET category_id = (SELECT id FROM core_contract_template_categories WHERE name = :name ORDER BY id LIMIT 1) WHERE category = :value',
                ['name' => $name, 'value' => $value],
            );
        }

        $this->addSql('DROP INDEX idx_contract_templates_category');
        $this->addSql('ALTER TABLE core_contract_templates DROP category');
        $this->addSql('ALTER TABLE core_contract_templates ADD CONSTRAINT FK_6DD7519612469DE2 FOREIGN KEY (category_id) REFERENCES core_contract_template_categories (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6DD7519612469DE2 ON core_contract_templates (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_contract_templates ADD category VARCHAR(32) DEFAULT NULL');

        foreach (self::TRADES as $value => $name) {
            $this->addSql(
                'UPDATE core_contract_templates t SET category = :value FROM core_contract_template_categories c WHERE c.id = t.category_id AND c.name = :name',
                ['name' => $name, 'value' => $value],
            );
        }

        $this->addSql('ALTER TABLE core_contract_templates DROP CONSTRAINT FK_6DD7519612469DE2');
        $this->addSql('DROP INDEX IDX_6DD7519612469DE2');
        $this->addSql('ALTER TABLE core_contract_templates DROP category_id');
        $this->addSql('CREATE INDEX idx_contract_templates_category ON core_contract_templates (category)');
        $this->addSql('DROP TABLE core_contract_template_categories');
        $this->addSql('DROP SEQUENCE seq_core_contract_template_category_id CASCADE');
    }
}
