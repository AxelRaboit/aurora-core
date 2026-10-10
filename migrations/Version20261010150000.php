<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Suivi des prospects » (10/10/2026): the pipeline's stages, where each
 * customer stands in it, its follow-up date, and the history of exchanges.
 *
 * Every new column on `core_customers` is nullable or has a default: existing
 * customers land in the first stage and have no follow-up, which is exactly
 * what they had before.
 */
final class Version20261010150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: prospect pipeline, follow-ups and customer interactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_pipeline_stage_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_customer_interaction_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_studio_pipeline_stages (name VARCHAR(100) NOT NULL, position INT DEFAULT 0 NOT NULL, colour_slot INT DEFAULT NULL, role VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE core_studio_customer_interactions (kind VARCHAR(20) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, summary TEXT NOT NULL, author_label VARCHAR(180) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, customer_id INT NOT NULL, author_user_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_customer_interaction_occurred ON core_studio_customer_interactions (customer_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_92592A39395C3F3 ON core_studio_customer_interactions (customer_id)');
        $this->addSql('CREATE INDEX IDX_92592A3E2544CD6 ON core_studio_customer_interactions (author_user_id)');
        $this->addSql('ALTER TABLE core_studio_customer_interactions ADD CONSTRAINT FK_92592A39395C3F3 FOREIGN KEY (customer_id) REFERENCES core_customers (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_customer_interactions ADD CONSTRAINT FK_92592A3E2544CD6 FOREIGN KEY (author_user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_customers ADD pipeline_position INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE core_customers ADD pipeline_stage_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD next_follow_up_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD follow_up_note VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD follow_up_notified_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD source VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD source_reference VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD estimated_value_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD estimated_value_currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD lost_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD pipeline_stage_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD CONSTRAINT FK_2D337E05B9CC4014 FOREIGN KEY (pipeline_stage_id) REFERENCES core_studio_pipeline_stages (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_customer_next_follow_up ON core_customers (next_follow_up_on)');
        $this->addSql('CREATE INDEX idx_customer_source_reference ON core_customers (source_reference)');
        $this->addSql('CREATE INDEX IDX_2D337E05B9CC4014 ON core_customers (pipeline_stage_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers DROP CONSTRAINT FK_2D337E05B9CC4014');
        $this->addSql('DROP INDEX idx_customer_next_follow_up');
        $this->addSql('DROP INDEX idx_customer_source_reference');
        $this->addSql('DROP INDEX IDX_2D337E05B9CC4014');
        $this->addSql('ALTER TABLE core_customers DROP pipeline_stage_id, DROP pipeline_position, DROP pipeline_stage_changed_at, DROP next_follow_up_on, DROP follow_up_note, DROP follow_up_notified_on, DROP source, DROP source_reference, DROP estimated_value_cents, DROP estimated_value_currency, DROP lost_reason');
        $this->addSql('DROP TABLE core_studio_customer_interactions');
        $this->addSql('DROP TABLE core_studio_pipeline_stages');
        $this->addSql('DROP SEQUENCE seq_core_customer_interaction_id');
        $this->addSql('DROP SEQUENCE seq_core_pipeline_stage_id');
    }
}
