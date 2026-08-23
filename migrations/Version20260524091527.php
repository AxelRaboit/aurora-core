<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Squash migration - full aurora-core schema as of mai 2026.
 *
 * Replaces the previous 64 incremental migrations accumulated during
 * the bundle's development. Generated via `doctrine:migrations:diff` on
 * an empty DB after deleting all `Version*.php` files. The resulting
 * file represents the current entity metadata in a single CREATE pass
 * (sequences + tables + indexes + foreign keys).
 *
 * Anyone running aurora-core CI / fresh dev install gets a single
 * migration to apply instead of 64.
 *
 * Existing dev DBs (aurora-client, aurora-welding) that have applied
 * the old migrations need a one-time cleanup of doctrine_migration_versions :
 *     DELETE FROM doctrine_migration_versions;
 *     php bin/console doctrine:migrations:version --add --all
 * The data is untouched - only the bookkeeping table is reset.
 */
final class Version20260524091527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squash - full aurora-core schema as of mai 2026 (replaces the previous 64 incremental migrations)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_access_request_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_agency_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_assistant_conversation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_assistant_message_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_assistant_mount_point_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_audit_log_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_invoice_line_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_invoice_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_ocr_job_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_tiers_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_block_note_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_comment_reaction_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_comment_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_company_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_contact_tag_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_contact_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_deal_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_cart_item_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_cart_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_listing_category_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_listing_category_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_listing_tag_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_listing_tag_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_listing_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_order_line_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_order_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_employee_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_product_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_form_field_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_form_field_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_form_submission_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_form_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_form_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_ged_category_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_ged_document_folder_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_ged_document_tag_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_ged_document_version_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_ged_document_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_markdown_note_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_media_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_media_folder_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_menu_item_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_menu_item_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_menu_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_mount_point_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_notification_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_budget_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_budget_item_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_budget_preset_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
                CREATE SEQUENCE seq_core_personal_finance_budget_preset_item_id INCREMENT BY 1 MINVALUE 1 START 1
            SQL);
        $this->addSql(<<<'SQL'
                CREATE SEQUENCE seq_core_personal_finance_categorization_rule_id INCREMENT BY 1 MINVALUE 1 START 1
            SQL);
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_category_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_goal_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
                CREATE SEQUENCE seq_core_personal_finance_recurring_transaction_id INCREMENT BY 1 MINVALUE 1 START 1
            SQL);
        $this->addSql(<<<'SQL'
                CREATE SEQUENCE seq_core_personal_finance_scheduled_transaction_id INCREMENT BY 1 MINVALUE 1 START 1
            SQL);
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_transaction_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_wallet_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
                CREATE SEQUENCE seq_core_personal_finance_wallet_invitation_id INCREMENT BY 1 MINVALUE 1 START 1
            SQL);
        $this->addSql('CREATE SEQUENCE seq_core_personal_finance_wallet_member_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_gallery_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_gallery_finalization_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_gallery_invite_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_gallery_item_comment_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_gallery_item_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_gallery_pick_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_planning_event_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_planning_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_post_it_note_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_post_revision_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_post_slug_history_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_post_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_post_type_field_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_post_type_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_post_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_column_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_label_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_saved_view_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_sprint_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_task_comment_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_task_item_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_task_time_entry_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_task_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_core_project_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_reset_password_request_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_service_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_taxonomy_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_taxonomy_term_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_taxonomy_term_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_taxonomy_translation_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_theme_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_user_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_vault_entry_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_vault_folder_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_vault_user_config_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
                CREATE TABLE app_sequence_counters (
                  prefix VARCHAR(64) NOT NULL,
                  year INT DEFAULT 0 NOT NULL,
                  last_value INT DEFAULT 0 NOT NULL,
                  PRIMARY KEY (prefix, year)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_access_requests (
                  reference VARCHAR(64) DEFAULT NULL,
                  token VARCHAR(64) NOT NULL,
                  requester_name VARCHAR(255) DEFAULT NULL,
                  message TEXT DEFAULT NULL,
                  status VARCHAR(20) DEFAULT 'pending' NOT NULL,
                  requester_email VARCHAR(255) NOT NULL,
                  expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B6CD538AEA34913 ON core_access_requests (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B6CD5385F37A13B ON core_access_requests (token)');
        $this->addSql('CREATE INDEX IDX_access_request_token ON core_access_requests (token)');
        $this->addSql('CREATE INDEX IDX_access_request_status ON core_access_requests (status)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_agencies (
                  name VARCHAR(150) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_assistant_conversations (
                  title TEXT DEFAULT NULL,
                  model VARCHAR(100) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  agency_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_C2DBED58CDEADB2A ON core_assistant_conversations (agency_id)');
        $this->addSql('CREATE INDEX idx_assistant_conversations_user ON core_assistant_conversations (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_assistant_messages (
                  role VARCHAR(20) NOT NULL,
                  content TEXT NOT NULL,
                  tool_calls JSON DEFAULT NULL,
                  tool_call_id VARCHAR(100) DEFAULT NULL,
                  tool_name VARCHAR(100) DEFAULT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  awaiting_confirmation BOOLEAN DEFAULT false NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  conversation_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_assistant_messages_conversation ON core_assistant_messages (conversation_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_assistant_mount_points (
                  name VARCHAR(100) NOT NULL,
                  path VARCHAR(1024) NOT NULL,
                  access VARCHAR(20) NOT NULL,
                  active BOOLEAN DEFAULT true NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_assistant_mount_points_user ON core_assistant_mount_points (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_audit_logs (
                  reference VARCHAR(64) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  module VARCHAR(30) NOT NULL,
                  action VARCHAR(100) NOT NULL,
                  entity_type VARCHAR(100) DEFAULT NULL,
                  entity_id INT DEFAULT NULL,
                  user_id INT DEFAULT NULL,
                  user_email VARCHAR(180) DEFAULT NULL,
                  user_name VARCHAR(180) DEFAULT NULL,
                  data JSON DEFAULT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EA63ACB9AEA34913 ON core_audit_logs (reference)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_billing_invoice_lines (
                  label VARCHAR(500) NOT NULL,
                  product_code VARCHAR(64) DEFAULT NULL,
                  unit VARCHAR(16) DEFAULT NULL,
                  quantity NUMERIC(12, 4) DEFAULT '1.0000' NOT NULL,
                  unit_price_cents INT DEFAULT NULL,
                  vat_rate_bp INT DEFAULT NULL,
                  total_net_cents INT DEFAULT NULL,
                  total_gross_cents INT DEFAULT NULL,
                  reference VARCHAR(100) DEFAULT NULL,
                  description TEXT DEFAULT NULL,
                  discount_cents INT DEFAULT NULL,
                  origin VARCHAR(2) DEFAULT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  id INT NOT NULL,
                  invoice_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_5C28EEC62989F1FD ON core_billing_invoice_lines (invoice_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_billing_invoices (
                  number VARCHAR(64) DEFAULT NULL,
                  supplier_number VARCHAR(64) DEFAULT NULL,
                  status VARCHAR(16) DEFAULT 'draft' NOT NULL,
                  issued_at DATE DEFAULT NULL,
                  due_at DATE DEFAULT NULL,
                  paid_at DATE DEFAULT NULL,
                  currency VARCHAR(3) DEFAULT 'EUR' NOT NULL,
                  subtotal_cents INT DEFAULT NULL,
                  total_net_cents INT DEFAULT NULL,
                  total_vat_cents INT DEFAULT NULL,
                  total_gross_cents INT DEFAULT NULL,
                  discount_cents INT DEFAULT NULL,
                  freight_cents INT DEFAULT NULL,
                  insurance_cents INT DEFAULT NULL,
                  discount_rate_bp INT DEFAULT NULL,
                  reference VARCHAR(100) DEFAULT NULL,
                  project VARCHAR(100) DEFAULT NULL,
                  incoterms VARCHAR(50) DEFAULT NULL,
                  delivery_date DATE DEFAULT NULL,
                  reverse_charge BOOLEAN DEFAULT false,
                  bank_details TEXT DEFAULT NULL,
                  purchase_order_ref VARCHAR(100) DEFAULT NULL,
                  payment_terms VARCHAR(255) DEFAULT NULL,
                  payment_method VARCHAR(50) DEFAULT NULL,
                  notes TEXT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  tiers_id INT DEFAULT NULL,
                  buyer_tiers_id INT DEFAULT NULL,
                  credit_note_id INT DEFAULT NULL,
                  document_id INT DEFAULT NULL,
                  ocr_job_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_173E636D68B77723 ON core_billing_invoices (tiers_id)');
        $this->addSql('CREATE INDEX IDX_173E636DD1710F83 ON core_billing_invoices (buyer_tiers_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_173E636D1C696F7A ON core_billing_invoices (credit_note_id)');
        $this->addSql('CREATE INDEX IDX_173E636DC33F7837 ON core_billing_invoices (document_id)');
        $this->addSql('CREATE INDEX IDX_173E636D27426A53 ON core_billing_invoices (ocr_job_id)');
        $this->addSql('CREATE INDEX idx_billing_invoice_status ON core_billing_invoices (status)');
        $this->addSql('CREATE INDEX idx_billing_invoice_issued_at ON core_billing_invoices (issued_at)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_billing_ocr_jobs (
                  reference VARCHAR(64) DEFAULT NULL,
                  status VARCHAR(32) DEFAULT 'queued' NOT NULL,
                  model_used VARCHAR(100) DEFAULT NULL,
                  started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  raw_doctr JSON DEFAULT NULL,
                  raw_vlm JSON DEFAULT NULL,
                  extracted JSON DEFAULT NULL,
                  confidence DOUBLE PRECISION DEFAULT NULL,
                  error TEXT DEFAULT NULL,
                  logs JSON DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  created_by_id INT DEFAULT NULL,
                  media_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DED831F7AEA34913 ON core_billing_ocr_jobs (reference)');
        $this->addSql('CREATE INDEX IDX_DED831F7B03A8386 ON core_billing_ocr_jobs (created_by_id)');
        $this->addSql('CREATE INDEX IDX_DED831F7EA9FDD75 ON core_billing_ocr_jobs (media_id)');
        $this->addSql('CREATE INDEX idx_billing_ocr_status ON core_billing_ocr_jobs (status)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_billing_tiers (
                  reference VARCHAR(64) DEFAULT NULL,
                  type VARCHAR(50) NOT NULL,
                  name VARCHAR(200) NOT NULL,
                  vat_number VARCHAR(64) DEFAULT NULL,
                  registration_number VARCHAR(64) DEFAULT NULL,
                  iban VARCHAR(34) DEFAULT NULL,
                  bic VARCHAR(11) DEFAULT NULL,
                  email VARCHAR(255) DEFAULT NULL,
                  phone VARCHAR(50) DEFAULT NULL,
                  address TEXT DEFAULT NULL,
                  country_code VARCHAR(2) DEFAULT NULL,
                  website VARCHAR(255) DEFAULT NULL,
                  legal_form VARCHAR(100) DEFAULT NULL,
                  bank_name VARCHAR(100) DEFAULT NULL,
                  notes TEXT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  company_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9B9DF4D0AEA34913 ON core_billing_tiers (reference)');
        $this->addSql('CREATE INDEX IDX_9B9DF4D0979B1AD6 ON core_billing_tiers (company_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_block_notes (
                  blocks JSON DEFAULT '[]' NOT NULL,
                  title TEXT DEFAULT NULL,
                  tags JSON DEFAULT '[]' NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  agency_id INT DEFAULT NULL,
                  parent_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_113BFE00CDEADB2A ON core_block_notes (agency_id)');
        $this->addSql('CREATE INDEX idx_block_notes_user ON core_block_notes (user_id)');
        $this->addSql('CREATE INDEX idx_block_notes_parent ON core_block_notes (parent_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_comment_reactions (
                  type VARCHAR(20) NOT NULL,
                  fingerprint VARCHAR(64) NOT NULL,
                  id INT NOT NULL,
                  comment_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_D60597D9F8697D13 ON core_comment_reactions (comment_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_comment_fingerprint ON core_comment_reactions (comment_id, fingerprint)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_comments (
                  reference VARCHAR(64) DEFAULT NULL,
                  author_name VARCHAR(100) NOT NULL,
                  author_email VARCHAR(180) NOT NULL,
                  content TEXT NOT NULL,
                  status VARCHAR(50) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  post_id INT NOT NULL,
                  parent_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E05CE089AEA34913 ON core_comments (reference)');
        $this->addSql('CREATE INDEX IDX_E05CE0894B89032C ON core_comments (post_id)');
        $this->addSql('CREATE INDEX IDX_E05CE089727ACA70 ON core_comments (parent_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_crm_companies (
                  reference VARCHAR(64) DEFAULT NULL,
                  name VARCHAR(150) NOT NULL,
                  industry VARCHAR(100) DEFAULT NULL,
                  website VARCHAR(255) DEFAULT NULL,
                  phone VARCHAR(50) DEFAULT NULL,
                  address VARCHAR(255) DEFAULT NULL,
                  notes TEXT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4B8BBD48AEA34913 ON core_crm_companies (reference)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_crm_contact_tags (
                  label VARCHAR(100) NOT NULL,
                  slug VARCHAR(120) NOT NULL,
                  color VARCHAR(7) DEFAULT '#6366F1' NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1E6EF199EA750E8 ON core_crm_contact_tags (label)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1E6EF199989D9B62 ON core_crm_contact_tags (slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_crm_contacts (
                  reference VARCHAR(64) DEFAULT NULL,
                  first_name VARCHAR(100) NOT NULL,
                  last_name VARCHAR(100) NOT NULL,
                  email VARCHAR(180) DEFAULT NULL,
                  phone VARCHAR(50) DEFAULT NULL,
                  notes TEXT DEFAULT NULL,
                  source VARCHAR(32) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  company_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F077E100AEA34913 ON core_crm_contacts (reference)');
        $this->addSql('CREATE INDEX IDX_F077E100979B1AD6 ON core_crm_contacts (company_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_crm_contact_tag_map (
                  contact_id INT NOT NULL,
                  contact_tag_id INT NOT NULL,
                  PRIMARY KEY (contact_id, contact_tag_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_2C26A5FFE7A1254A ON core_crm_contact_tag_map (contact_id)');
        $this->addSql('CREATE INDEX IDX_2C26A5FF2A405490 ON core_crm_contact_tag_map (contact_tag_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_crm_deals (
                  reference VARCHAR(64) DEFAULT NULL,
                  name VARCHAR(200) NOT NULL,
                  stage VARCHAR(20) DEFAULT 'lead' NOT NULL,
                  value NUMERIC(12, 2) DEFAULT NULL,
                  closing_date DATE DEFAULT NULL,
                  notes TEXT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  contact_id INT DEFAULT NULL,
                  company_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1C50EB87AEA34913 ON core_crm_deals (reference)');
        $this->addSql('CREATE INDEX IDX_1C50EB87E7A1254A ON core_crm_deals (contact_id)');
        $this->addSql('CREATE INDEX IDX_1C50EB87979B1AD6 ON core_crm_deals (company_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_cart_items (
                  reference VARCHAR(64) DEFAULT NULL,
                  quantity INT NOT NULL,
                  unit_price_cents INT NOT NULL,
                  currency VARCHAR(3) NOT NULL,
                  id INT NOT NULL,
                  cart_id INT NOT NULL,
                  listing_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BE453BB1AEA34913 ON core_ecommerce_cart_items (reference)');
        $this->addSql('CREATE INDEX IDX_BE453BB11AD5CDBF ON core_ecommerce_cart_items (cart_id)');
        $this->addSql('CREATE INDEX IDX_BE453BB1D4619D1A ON core_ecommerce_cart_items (listing_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_ecommerce_cart_item_listing ON core_ecommerce_cart_items (cart_id, listing_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_carts (
                  reference VARCHAR(64) DEFAULT NULL,
                  session_id VARCHAR(64) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_349FCE79AEA34913 ON core_ecommerce_carts (reference)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ecommerce_cart_session ON core_ecommerce_carts (session_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ecommerce_cart_user ON core_ecommerce_carts (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_listing_categories (
                  position INT DEFAULT 0 NOT NULL,
                  is_visible BOOLEAN DEFAULT true NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  parent_id INT DEFAULT NULL,
                  image_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_3015D435727ACA70 ON core_ecommerce_listing_categories (parent_id)');
        $this->addSql('CREATE INDEX IDX_3015D4353DA5256D ON core_ecommerce_listing_categories (image_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_listing_category_translations (
                  locale VARCHAR(10) NOT NULL,
                  name VARCHAR(150) NOT NULL,
                  slug VARCHAR(200) NOT NULL,
                  description TEXT DEFAULT NULL,
                  seo_title VARCHAR(200) DEFAULT NULL,
                  seo_description TEXT DEFAULT NULL,
                  id INT NOT NULL,
                  category_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE INDEX IDX_B2F8D54B12469DE2 ON core_ecommerce_listing_category_translations (category_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_listing_category_translation_locale ON core_ecommerce_listing_category_translations (category_id, locale)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_listing_category_translation_slug ON core_ecommerce_listing_category_translations (locale, slug)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_listing_tag_translations (
                  locale VARCHAR(10) NOT NULL,
                  name VARCHAR(100) NOT NULL,
                  slug VARCHAR(150) NOT NULL,
                  description TEXT DEFAULT NULL,
                  id INT NOT NULL,
                  tag_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_83F405EEBAD26311 ON core_ecommerce_listing_tag_translations (tag_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_listing_tag_translation_locale ON core_ecommerce_listing_tag_translations (tag_id, locale)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_listing_tag_translation_slug ON core_ecommerce_listing_tag_translations (locale, slug)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_listing_tags (
                  color VARCHAR(7) DEFAULT '#6366F1' NOT NULL,
                  is_visible BOOLEAN DEFAULT true NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_listings (
                  reference VARCHAR(64) DEFAULT NULL,
                  slug VARCHAR(200) NOT NULL,
                  marketing_title VARCHAR(200) DEFAULT NULL,
                  marketing_description TEXT DEFAULT NULL,
                  is_visible_on_shop BOOLEAN DEFAULT true NOT NULL,
                  seo_title VARCHAR(200) DEFAULT NULL,
                  seo_description TEXT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  product_id INT NOT NULL,
                  featured_image_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E66FDB5AEA34913 ON core_ecommerce_listings (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E66FDB54584665A ON core_ecommerce_listings (product_id)');
        $this->addSql('CREATE INDEX IDX_2E66FDB53569D950 ON core_ecommerce_listings (featured_image_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ecommerce_listing_slug ON core_ecommerce_listings (slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_listing_category_map (
                  listing_id INT NOT NULL,
                  listing_category_id INT NOT NULL,
                  PRIMARY KEY (listing_id, listing_category_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_A77BDC76D4619D1A ON core_ecommerce_listing_category_map (listing_id)');
        $this->addSql('CREATE INDEX IDX_A77BDC76455844B0 ON core_ecommerce_listing_category_map (listing_category_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_listing_tag_map (
                  listing_id INT NOT NULL,
                  listing_tag_id INT NOT NULL,
                  PRIMARY KEY (listing_id, listing_tag_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_B1747780D4619D1A ON core_ecommerce_listing_tag_map (listing_id)');
        $this->addSql('CREATE INDEX IDX_B17477805E2A42C2 ON core_ecommerce_listing_tag_map (listing_tag_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_order_lines (
                  reference VARCHAR(64) DEFAULT NULL,
                  title_snapshot VARCHAR(200) NOT NULL,
                  reference_snapshot VARCHAR(64) NOT NULL,
                  quantity INT NOT NULL,
                  unit_price_cents INT NOT NULL,
                  currency VARCHAR(3) NOT NULL,
                  id INT NOT NULL,
                  order_id INT NOT NULL,
                  listing_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_764F7FD1AEA34913 ON core_ecommerce_order_lines (reference)');
        $this->addSql('CREATE INDEX IDX_764F7FD18D9F6D38 ON core_ecommerce_order_lines (order_id)');
        $this->addSql('CREATE INDEX IDX_764F7FD1D4619D1A ON core_ecommerce_order_lines (listing_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ecommerce_orders (
                  number VARCHAR(32) NOT NULL,
                  token VARCHAR(64) NOT NULL,
                  status VARCHAR(16) DEFAULT 'pending' NOT NULL,
                  email VARCHAR(180) NOT NULL,
                  name VARCHAR(200) NOT NULL,
                  address_line1 VARCHAR(200) DEFAULT NULL,
                  address_line2 VARCHAR(200) DEFAULT NULL,
                  city VARCHAR(100) DEFAULT NULL,
                  postal_code VARCHAR(20) DEFAULT NULL,
                  country VARCHAR(2) DEFAULT NULL,
                  notes TEXT DEFAULT NULL,
                  total_cents INT NOT NULL,
                  currency VARCHAR(3) NOT NULL,
                  stripe_payment_intent_id VARCHAR(64) DEFAULT NULL,
                  refunded_cents INT DEFAULT NULL,
                  locale VARCHAR(5) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  customer_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_13EC44319395C3F3 ON core_ecommerce_orders (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ecommerce_order_number ON core_ecommerce_orders (number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ecommerce_order_token ON core_ecommerce_orders (token)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_employees (
                  first_name VARCHAR(100) NOT NULL,
                  last_name VARCHAR(100) NOT NULL,
                  job_title VARCHAR(150) DEFAULT NULL,
                  phone VARCHAR(30) DEFAULT NULL,
                  work_email VARCHAR(180) DEFAULT NULL,
                  hired_at DATE DEFAULT NULL,
                  left_at DATE DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT DEFAULT NULL,
                  service_id INT DEFAULT NULL,
                  agency_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F5E2F324A76ED395 ON core_employees (user_id)');
        $this->addSql('CREATE INDEX IDX_F5E2F324ED5CA9E6 ON core_employees (service_id)');
        $this->addSql('CREATE INDEX IDX_F5E2F324CDEADB2A ON core_employees (agency_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_erp_products (
                  name VARCHAR(150) NOT NULL,
                  reference VARCHAR(64) NOT NULL,
                  description TEXT DEFAULT NULL,
                  price_cents INT DEFAULT NULL,
                  currency VARCHAR(3) DEFAULT 'EUR' NOT NULL,
                  status VARCHAR(16) NOT NULL,
                  type VARCHAR(16) DEFAULT 'physical' NOT NULL,
                  stock_quantity INT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  image_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_4DE001913DA5256D ON core_erp_products (image_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_erp_product_reference ON core_erp_products (reference)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_form_field_translations (
                  locale VARCHAR(10) NOT NULL,
                  label VARCHAR(200) NOT NULL,
                  placeholder VARCHAR(200) DEFAULT NULL,
                  options JSON NOT NULL,
                  id INT NOT NULL,
                  field_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_490A58F5443707B0 ON core_form_field_translations (field_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX UNIQ_490A58F5443707B04180C698 ON core_form_field_translations (field_id, locale)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_form_fields (
                  reference VARCHAR(64) DEFAULT NULL,
                  type VARCHAR(50) NOT NULL,
                  required BOOLEAN NOT NULL,
                  position INT NOT NULL,
                  conditions JSON DEFAULT NULL,
                  conditions_logic VARCHAR(3) DEFAULT NULL,
                  step INT DEFAULT NULL,
                  id INT NOT NULL,
                  form_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AB3AA94CAEA34913 ON core_form_fields (reference)');
        $this->addSql('CREATE INDEX IDX_AB3AA94C5FF69B7D ON core_form_fields (form_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_form_submissions (
                  reference VARCHAR(64) DEFAULT NULL,
                  data JSON NOT NULL,
                  locale VARCHAR(10) NOT NULL,
                  submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  ip VARCHAR(45) DEFAULT NULL,
                  id INT NOT NULL,
                  form_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ADC7DCE2AEA34913 ON core_form_submissions (reference)');
        $this->addSql('CREATE INDEX IDX_ADC7DCE25FF69B7D ON core_form_submissions (form_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_form_translations (
                  locale VARCHAR(10) NOT NULL,
                  title VARCHAR(200) NOT NULL,
                  slug VARCHAR(200) NOT NULL,
                  description TEXT DEFAULT NULL,
                  id INT NOT NULL,
                  form_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_ABF898D95FF69B7D ON core_form_translations (form_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ABF898D95FF69B7D4180C698 ON core_form_translations (form_id, locale)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ABF898D94180C698989D9B62 ON core_form_translations (locale, slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_forms (
                  reference VARCHAR(64) DEFAULT NULL,
                  notify_email VARCHAR(180) DEFAULT NULL,
                  webhook_url VARCHAR(500) DEFAULT NULL,
                  crm_sync BOOLEAN DEFAULT false NOT NULL,
                  steps JSON DEFAULT NULL,
                  active BOOLEAN NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ABBE3A17AEA34913 ON core_forms (reference)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ged_document_categories (
                  name VARCHAR(150) NOT NULL,
                  slug VARCHAR(180) NOT NULL,
                  description TEXT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2D74479A989D9B62 ON core_ged_document_categories (slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ged_document_folders (
                  name VARCHAR(150) NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  parent_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_B0EE1B81727ACA70 ON core_ged_document_folders (parent_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ged_document_tags (
                  name VARCHAR(100) NOT NULL,
                  color VARCHAR(7) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ged_document_versions (
                  version_number INT NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  note VARCHAR(255) DEFAULT NULL,
                  id INT NOT NULL,
                  document_id INT NOT NULL,
                  file_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_1392373DC33F7837 ON core_ged_document_versions (document_id)');
        $this->addSql('CREATE INDEX IDX_1392373D93CB796C ON core_ged_document_versions (file_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ged_documents (
                  reference VARCHAR(64) DEFAULT NULL,
                  title VARCHAR(200) NOT NULL,
                  description TEXT DEFAULT NULL,
                  status VARCHAR(20) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  category_id INT DEFAULT NULL,
                  file_id INT DEFAULT NULL,
                  folder_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A80B359AAEA34913 ON core_ged_documents (reference)');
        $this->addSql('CREATE INDEX IDX_A80B359A12469DE2 ON core_ged_documents (category_id)');
        $this->addSql('CREATE INDEX IDX_A80B359A93CB796C ON core_ged_documents (file_id)');
        $this->addSql('CREATE INDEX IDX_A80B359A162CB942 ON core_ged_documents (folder_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_ged_document_tag_map (
                  document_id INT NOT NULL,
                  document_tag_id INT NOT NULL,
                  PRIMARY KEY (document_id, document_tag_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_DCAAF837C33F7837 ON core_ged_document_tag_map (document_id)');
        $this->addSql('CREATE INDEX IDX_DCAAF8374B0D277 ON core_ged_document_tag_map (document_tag_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_locales (
                  name VARCHAR(100) NOT NULL,
                  is_default BOOLEAN NOT NULL,
                  is_active BOOLEAN NOT NULL,
                  position INT NOT NULL,
                  code VARCHAR(10) NOT NULL,
                  PRIMARY KEY (code)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_markdown_notes (
                  title TEXT DEFAULT NULL,
                  content TEXT DEFAULT NULL,
                  tags JSON DEFAULT '[]' NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  agency_id INT DEFAULT NULL,
                  parent_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_650ACC0BCDEADB2A ON core_markdown_notes (agency_id)');
        $this->addSql('CREATE INDEX idx_markdown_notes_user ON core_markdown_notes (user_id)');
        $this->addSql('CREATE INDEX idx_markdown_notes_parent ON core_markdown_notes (parent_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_media (
                  reference VARCHAR(64) DEFAULT NULL,
                  filename VARCHAR(255) NOT NULL,
                  original_name VARCHAR(255) NOT NULL,
                  mime_type VARCHAR(100) NOT NULL,
                  size INT NOT NULL,
                  path VARCHAR(255) NOT NULL,
                  width INT DEFAULT NULL,
                  height INT DEFAULT NULL,
                  alt VARCHAR(255) DEFAULT NULL,
                  caption TEXT DEFAULT NULL,
                  focal_x DOUBLE PRECISION DEFAULT NULL,
                  focal_y DOUBLE PRECISION DEFAULT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  variants JSON DEFAULT '{}' NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  uploaded_by_id INT DEFAULT NULL,
                  folder_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3CAD80ECAEA34913 ON core_media (reference)');
        $this->addSql('CREATE INDEX IDX_3CAD80ECA2B28FE8 ON core_media (uploaded_by_id)');
        $this->addSql('CREATE INDEX IDX_3CAD80EC162CB942 ON core_media (folder_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_media_folders (
                  reference VARCHAR(64) DEFAULT NULL,
                  name VARCHAR(150) NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  parent_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1745BF19AEA34913 ON core_media_folders (reference)');
        $this->addSql('CREATE INDEX IDX_media_folders_parent ON core_media_folders (parent_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_menu_item_translations (
                  locale VARCHAR(10) NOT NULL,
                  label VARCHAR(255) DEFAULT NULL,
                  id INT NOT NULL,
                  menu_item_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_7C582A479AB44FE0 ON core_menu_item_translations (menu_item_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_menu_item_locale ON core_menu_item_translations (menu_item_id, locale)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_menu_items (
                  reference VARCHAR(64) DEFAULT NULL,
                  target_type VARCHAR(30) NOT NULL,
                  target_id INT DEFAULT NULL,
                  custom_url VARCHAR(1000) DEFAULT NULL,
                  open_in_new_tab BOOLEAN NOT NULL,
                  css_class VARCHAR(255) DEFAULT NULL,
                  visibility VARCHAR(30) NOT NULL,
                  position INT NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  parent_id INT DEFAULT NULL,
                  menu_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4CFE4ECBAEA34913 ON core_menu_items (reference)');
        $this->addSql('CREATE INDEX IDX_4CFE4ECB727ACA70 ON core_menu_items (parent_id)');
        $this->addSql('CREATE INDEX IDX_4CFE4ECBCCD7E912 ON core_menu_items (menu_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_menus (
                  name VARCHAR(100) NOT NULL,
                  location VARCHAR(100) NOT NULL,
                  description VARCHAR(500) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_24F4292F5E9E89CB ON core_menus (location)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_mount_points (
                  name VARCHAR(100) NOT NULL,
                  type VARCHAR(20) NOT NULL,
                  host VARCHAR(255) NOT NULL,
                  port INT DEFAULT NULL,
                  username VARCHAR(100) DEFAULT NULL,
                  password TEXT DEFAULT NULL,
                  database VARCHAR(100) DEFAULT NULL,
                  ssh_public_key TEXT DEFAULT NULL,
                  ssh_private_key TEXT DEFAULT NULL,
                  config JSON NOT NULL,
                  last_tested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  last_test_successful BOOLEAN DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_notifications (
                  type VARCHAR(80) NOT NULL,
                  title VARCHAR(255) NOT NULL,
                  body TEXT DEFAULT NULL,
                  url VARCHAR(500) DEFAULT NULL,
                  data JSON DEFAULT NULL,
                  read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  recipient_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_E8A55A8CE92F8F78 ON core_notifications (recipient_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_budget (
                  month DATE NOT NULL,
                  notes VARCHAR(255) DEFAULT NULL,
                  rolled_over_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_81763E59712520F3 ON core_personal_finance_budget (wallet_id)');
        $this->addSql('CREATE INDEX idx_pf_budget_user ON core_personal_finance_budget (user_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_pf_budget_wallet_month ON core_personal_finance_budget (wallet_id, month)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_budget_item (
                  section VARCHAR(16) NOT NULL,
                  label VARCHAR(120) NOT NULL,
                  planned_amount NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                  carried_over NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  notes VARCHAR(255) DEFAULT NULL,
                  repeat_next_month BOOLEAN DEFAULT false NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  budget_id INT NOT NULL,
                  category_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_pf_budget_item_budget ON core_personal_finance_budget_item (budget_id)');
        $this->addSql('CREATE INDEX idx_pf_budget_item_category ON core_personal_finance_budget_item (category_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_budget_preset (
                  name VARCHAR(120) NOT NULL,
                  description VARCHAR(500) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_pf_budget_preset_user ON core_personal_finance_budget_preset (user_id)');
        $this->addSql('CREATE INDEX idx_pf_budget_preset_wallet ON core_personal_finance_budget_preset (wallet_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_budget_preset_item (
                  section VARCHAR(16) NOT NULL,
                  label VARCHAR(120) NOT NULL,
                  planned_amount NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  notes VARCHAR(255) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  preset_id INT NOT NULL,
                  category_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_9119390512469DE2 ON core_personal_finance_budget_preset_item (category_id)');
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_budget_preset_item_preset ON core_personal_finance_budget_preset_item (preset_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_categorization_rule (
                  pattern VARCHAR(255) NOT NULL,
                  hits INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  category_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_3DF8CF0CA76ED395 ON core_personal_finance_categorization_rule (user_id)');
        $this->addSql('CREATE INDEX idx_pf_categ_rule_pattern ON core_personal_finance_categorization_rule (pattern)');
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_categ_rule_category ON core_personal_finance_categorization_rule (category_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_pf_categ_rule_user_pattern ON core_personal_finance_categorization_rule (user_id, pattern)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_category (
                  name VARCHAR(120) NOT NULL,
                  is_system BOOLEAN DEFAULT false NOT NULL,
                  system_key VARCHAR(120) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_4346D771A76ED395 ON core_personal_finance_category (user_id)');
        $this->addSql('CREATE INDEX idx_pf_category_wallet ON core_personal_finance_category (wallet_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_pf_category_system_key ON core_personal_finance_category (wallet_id, system_key)
                WHERE
                  (system_key IS NOT NULL)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_goal (
                  name VARCHAR(120) NOT NULL,
                  target_amount NUMERIC(10, 2) NOT NULL,
                  saved_amount NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                  deadline DATE DEFAULT NULL,
                  color VARCHAR(7) DEFAULT NULL,
                  tracking_mode VARCHAR(16) DEFAULT 'expense_only' NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT DEFAULT NULL,
                  category_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_pf_goal_user ON core_personal_finance_goal (user_id)');
        $this->addSql('CREATE INDEX idx_pf_goal_category ON core_personal_finance_goal (category_id)');
        $this->addSql('CREATE INDEX idx_pf_goal_wallet ON core_personal_finance_goal (wallet_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_recurring_transaction (
                  type VARCHAR(16) NOT NULL,
                  amount NUMERIC(10, 2) NOT NULL,
                  description VARCHAR(255) DEFAULT NULL,
                  day_of_month SMALLINT NOT NULL,
                  active BOOLEAN DEFAULT true NOT NULL,
                  last_generated_at DATE DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  category_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_57EACDF012469DE2 ON core_personal_finance_recurring_transaction (category_id)');
        $this->addSql('CREATE INDEX idx_pf_recurring_user ON core_personal_finance_recurring_transaction (user_id)');
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_recurring_wallet ON core_personal_finance_recurring_transaction (wallet_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_recurring_active_day ON core_personal_finance_recurring_transaction (active, day_of_month)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_scheduled_transaction (
                  type VARCHAR(16) NOT NULL,
                  amount NUMERIC(10, 2) NOT NULL,
                  description VARCHAR(255) DEFAULT NULL,
                  scheduled_date DATE NOT NULL,
                  generated BOOLEAN DEFAULT false NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  category_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_44AE2B2712469DE2 ON core_personal_finance_scheduled_transaction (category_id)');
        $this->addSql('CREATE INDEX idx_pf_scheduled_user ON core_personal_finance_scheduled_transaction (user_id)');
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_scheduled_wallet ON core_personal_finance_scheduled_transaction (wallet_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_scheduled_date ON core_personal_finance_scheduled_transaction (scheduled_date)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_transaction (
                  type VARCHAR(16) NOT NULL,
                  amount NUMERIC(10, 2) NOT NULL,
                  description VARCHAR(255) DEFAULT NULL,
                  date DATE NOT NULL,
                  tags JSON DEFAULT NULL,
                  transfer_id VARCHAR(36) DEFAULT NULL,
                  split_id VARCHAR(36) DEFAULT NULL,
                  attachment_path VARCHAR(255) DEFAULT NULL,
                  attachment_original_name VARCHAR(255) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  category_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_2C5E85AA76ED395 ON core_personal_finance_transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_2C5E85A712520F3 ON core_personal_finance_transaction (wallet_id)');
        $this->addSql('CREATE INDEX IDX_2C5E85A12469DE2 ON core_personal_finance_transaction (category_id)');
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_transaction_wallet_date ON core_personal_finance_transaction (wallet_id, date)
            SQL);
        $this->addSql('CREATE INDEX idx_pf_transaction_user_date ON core_personal_finance_transaction (user_id, date)');
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_pf_transaction_category_date ON core_personal_finance_transaction (category_id, date)
            SQL);
        $this->addSql('CREATE INDEX idx_pf_transaction_transfer ON core_personal_finance_transaction (transfer_id)');
        $this->addSql('CREATE INDEX idx_pf_transaction_split ON core_personal_finance_transaction (split_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_wallet (
                  name VARCHAR(120) NOT NULL,
                  start_balance NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                  mode VARCHAR(16) NOT NULL,
                  show_on_dashboard BOOLEAN DEFAULT true NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  owner_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_8EEC5B3D7E3C61F9 ON core_personal_finance_wallet (owner_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_wallet_invitation (
                  email VARCHAR(180) NOT NULL,
                  role VARCHAR(16) NOT NULL,
                  token VARCHAR(64) NOT NULL,
                  expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  declined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  invited_by_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AAEF0F695F37A13B ON core_personal_finance_wallet_invitation (token)');
        $this->addSql('CREATE INDEX IDX_AAEF0F69A7B4A7E3 ON core_personal_finance_wallet_invitation (invited_by_id)');
        $this->addSql('CREATE INDEX IDX_AAEF0F69712520F3 ON core_personal_finance_wallet_invitation (wallet_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_pf_wallet_invitation_email ON core_personal_finance_wallet_invitation (wallet_id, email)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_personal_finance_wallet_member (
                  role VARCHAR(16) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  wallet_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_C688CA8BA76ED395 ON core_personal_finance_wallet_member (user_id)');
        $this->addSql('CREATE INDEX IDX_C688CA8B712520F3 ON core_personal_finance_wallet_member (wallet_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_pf_wallet_member ON core_personal_finance_wallet_member (wallet_id, user_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_photo_galleries (
                  reference VARCHAR(64) DEFAULT NULL,
                  slug VARCHAR(80) NOT NULL,
                  title VARCHAR(200) NOT NULL,
                  description TEXT DEFAULT NULL,
                  password_hash VARCHAR(255) DEFAULT NULL,
                  expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  allow_originals BOOLEAN DEFAULT true NOT NULL,
                  allow_zip_download BOOLEAN DEFAULT true NOT NULL,
                  picks_require_identity BOOLEAN DEFAULT false NOT NULL,
                  max_picks INT DEFAULT NULL,
                  allow_visitor_comments BOOLEAN DEFAULT false NOT NULL,
                  watermark_enabled BOOLEAN DEFAULT false NOT NULL,
                  watermark_text VARCHAR(100) DEFAULT NULL,
                  finalized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  finalized_by_name VARCHAR(200) DEFAULT NULL,
                  finalized_by_email VARCHAR(180) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  created_by_id INT NOT NULL,
                  cover_media_id INT DEFAULT NULL,
                  client_contact_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_870CFACEAEA34913 ON core_photo_galleries (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_870CFACE989D9B62 ON core_photo_galleries (slug)');
        $this->addSql('CREATE INDEX IDX_870CFACEB03A8386 ON core_photo_galleries (created_by_id)');
        $this->addSql('CREATE INDEX IDX_870CFACE329A1B2E ON core_photo_galleries (cover_media_id)');
        $this->addSql('CREATE INDEX IDX_870CFACE77F5180B ON core_photo_galleries (client_contact_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_photo_gallery_finalizations (
                  reference VARCHAR(64) DEFAULT NULL,
                  visitor_token VARCHAR(64) NOT NULL,
                  visitor_name VARCHAR(200) DEFAULT NULL,
                  visitor_email VARCHAR(180) DEFAULT NULL,
                  finalized_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  gallery_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6935614FAEA34913 ON core_photo_gallery_finalizations (reference)');
        $this->addSql('CREATE INDEX IDX_6935614F4E7AF8F ON core_photo_gallery_finalizations (gallery_id)');
        $this->addSql('CREATE INDEX idx_finalization_token ON core_photo_gallery_finalizations (visitor_token)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_finalization_per_visitor ON core_photo_gallery_finalizations (gallery_id, visitor_token)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_photo_gallery_invites (
                  reference VARCHAR(64) DEFAULT NULL,
                  name VARCHAR(200) NOT NULL,
                  email VARCHAR(180) NOT NULL,
                  token VARCHAR(64) NOT NULL,
                  visitor_token VARCHAR(64) NOT NULL,
                  invited_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  id INT NOT NULL,
                  gallery_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CD575646AEA34913 ON core_photo_gallery_invites (reference)');
        $this->addSql('CREATE INDEX IDX_CD5756464E7AF8F ON core_photo_gallery_invites (gallery_id)');
        $this->addSql('CREATE INDEX idx_invite_visitor_token ON core_photo_gallery_invites (visitor_token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invite_token ON core_photo_gallery_invites (token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invite_per_email ON core_photo_gallery_invites (gallery_id, email)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_photo_gallery_item_comments (
                  reference VARCHAR(64) DEFAULT NULL,
                  visitor_token VARCHAR(64) NOT NULL,
                  visitor_name VARCHAR(200) DEFAULT NULL,
                  visitor_email VARCHAR(180) DEFAULT NULL,
                  content TEXT NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  gallery_item_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B77E76C8AEA34913 ON core_photo_gallery_item_comments (reference)');
        $this->addSql('CREATE INDEX idx_comment_item ON core_photo_gallery_item_comments (gallery_item_id)');
        $this->addSql('CREATE INDEX idx_comment_token ON core_photo_gallery_item_comments (visitor_token)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_photo_gallery_items (
                  reference VARCHAR(64) DEFAULT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  number INT DEFAULT 0 NOT NULL,
                  taken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  caption TEXT DEFAULT NULL,
                  id INT NOT NULL,
                  gallery_id INT NOT NULL,
                  media_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8B9DD5EAAEA34913 ON core_photo_gallery_items (reference)');
        $this->addSql('CREATE INDEX IDX_8B9DD5EA4E7AF8F ON core_photo_gallery_items (gallery_id)');
        $this->addSql('CREATE INDEX IDX_8B9DD5EAEA9FDD75 ON core_photo_gallery_items (media_id)');
        $this->addSql('CREATE INDEX idx_gallery_position ON core_photo_gallery_items (gallery_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_gallery_media ON core_photo_gallery_items (gallery_id, media_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_gallery_number ON core_photo_gallery_items (gallery_id, number)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_photo_gallery_picks (
                  reference VARCHAR(64) DEFAULT NULL,
                  visitor_token VARCHAR(64) NOT NULL,
                  visitor_name VARCHAR(200) DEFAULT NULL,
                  visitor_email VARCHAR(180) DEFAULT NULL,
                  picked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  kind VARCHAR(32) DEFAULT 'favorite' NOT NULL,
                  id INT NOT NULL,
                  gallery_item_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_16C9746FAEA34913 ON core_photo_gallery_picks (reference)');
        $this->addSql('CREATE INDEX IDX_16C9746F2A151376 ON core_photo_gallery_picks (gallery_item_id)');
        $this->addSql('CREATE INDEX idx_pick_token ON core_photo_gallery_picks (visitor_token)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_pick_per_visitor ON core_photo_gallery_picks (
                  gallery_item_id, visitor_token, kind
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_planning_events (
                  title VARCHAR(255) NOT NULL,
                  description TEXT DEFAULT NULL,
                  location VARCHAR(255) DEFAULT NULL,
                  start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  all_day BOOLEAN DEFAULT false NOT NULL,
                  status VARCHAR(20) DEFAULT 'confirmed' NOT NULL,
                  source_type VARCHAR(64) DEFAULT NULL,
                  source_id INT DEFAULT NULL,
                  source_label VARCHAR(255) DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  planning_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_4E5AB77D3D865311 ON core_planning_events (planning_id)');
        $this->addSql('CREATE INDEX idx_planning_event_planning_start ON core_planning_events (planning_id, start_at)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_planning_event_source ON core_planning_events (source_type, source_id)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_planning_event_attendees (
                  event_id INT NOT NULL,
                  user_id INT NOT NULL,
                  PRIMARY KEY (event_id, user_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_F280AEA271F7E88B ON core_planning_event_attendees (event_id)');
        $this->addSql('CREATE INDEX IDX_F280AEA2A76ED395 ON core_planning_event_attendees (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_plannings (
                  name VARCHAR(150) NOT NULL,
                  description TEXT DEFAULT NULL,
                  color VARCHAR(7) DEFAULT '#3b82f6' NOT NULL,
                  timezone VARCHAR(64) DEFAULT 'Europe/Paris' NOT NULL,
                  visibility VARCHAR(20) DEFAULT 'private' NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  owner_id INT DEFAULT NULL,
                  agency_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_6431B97E3C61F9 ON core_plannings (owner_id)');
        $this->addSql('CREATE INDEX IDX_6431B9CDEADB2A ON core_plannings (agency_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_it_notes (
                  title TEXT DEFAULT NULL,
                  content TEXT DEFAULT NULL,
                  color VARCHAR(7) DEFAULT '#FFEB3B' NOT NULL,
                  position_x INT DEFAULT 0 NOT NULL,
                  position_y INT DEFAULT 0 NOT NULL,
                  width INT DEFAULT 220 NOT NULL,
                  height INT DEFAULT 220 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  agency_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_BF08188CCDEADB2A ON core_post_it_notes (agency_id)');
        $this->addSql('CREATE INDEX idx_post_it_notes_user ON core_post_it_notes (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_revisions (
                  post_version INT NOT NULL,
                  status VARCHAR(50) NOT NULL,
                  snapshot JSON NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  author_id INT DEFAULT NULL,
                  post_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_D383B5FDF675F31B ON core_post_revisions (author_id)');
        $this->addSql('CREATE INDEX IDX_D383B5FD4B89032C ON core_post_revisions (post_id)');
        $this->addSql('CREATE INDEX IDX_post_revision_post_created ON core_post_revisions (post_id, created_at)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_slug_history (
                  locale VARCHAR(10) NOT NULL,
                  slug VARCHAR(255) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  post_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_post_slug_history_post ON core_post_slug_history (post_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX UNIQ_post_slug_history_locale_slug ON core_post_slug_history (locale, slug)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_translations (
                  locale VARCHAR(10) NOT NULL,
                  title VARCHAR(255) DEFAULT NULL,
                  slug VARCHAR(255) DEFAULT NULL,
                  blocks JSON NOT NULL,
                  meta_title VARCHAR(255) DEFAULT NULL,
                  meta_description TEXT DEFAULT NULL,
                  custom_fields JSON NOT NULL,
                  canonical_url VARCHAR(500) DEFAULT NULL,
                  noindex BOOLEAN DEFAULT false NOT NULL,
                  focus_keyword VARCHAR(120) DEFAULT NULL,
                  json_ld JSON DEFAULT NULL,
                  search_content TEXT DEFAULT NULL,
                  id INT NOT NULL,
                  og_image_id INT DEFAULT NULL,
                  post_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_6A82AE686EFCB8B8 ON core_post_translations (og_image_id)');
        $this->addSql('CREATE INDEX IDX_6A82AE684B89032C ON core_post_translations (post_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6A82AE684B89032C4180C698 ON core_post_translations (post_id, locale)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_type_fields (
                  name VARCHAR(100) NOT NULL,
                  label VARCHAR(100) NOT NULL,
                  type VARCHAR(50) NOT NULL,
                  required BOOLEAN NOT NULL,
                  translatable BOOLEAN NOT NULL,
                  options JSON NOT NULL,
                  position INT NOT NULL,
                  id INT NOT NULL,
                  post_type_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_D4BF279BF8A43BA0 ON core_post_type_fields (post_type_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_types (
                  slug VARCHAR(100) NOT NULL,
                  label VARCHAR(100) NOT NULL,
                  icon VARCHAR(50) DEFAULT NULL,
                  has_archive BOOLEAN NOT NULL,
                  is_built_in BOOLEAN NOT NULL,
                  supports JSON NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F267813E989D9B62 ON core_post_types (slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_type_taxonomies (
                  post_type_id INT NOT NULL,
                  taxonomy_id INT NOT NULL,
                  PRIMARY KEY (post_type_id, taxonomy_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_8176B93CF8A43BA0 ON core_post_type_taxonomies (post_type_id)');
        $this->addSql('CREATE INDEX IDX_8176B93C9557E6F6 ON core_post_type_taxonomies (taxonomy_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_posts (
                  reference VARCHAR(64) DEFAULT NULL,
                  version INT DEFAULT 1 NOT NULL,
                  status VARCHAR(50) NOT NULL,
                  published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  comments_enabled BOOLEAN DEFAULT true NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  author_id INT DEFAULT NULL,
                  post_type_id INT NOT NULL,
                  featured_media_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DEDC9B1AAEA34913 ON core_posts (reference)');
        $this->addSql('CREATE INDEX IDX_DEDC9B1AF675F31B ON core_posts (author_id)');
        $this->addSql('CREATE INDEX IDX_DEDC9B1AF8A43BA0 ON core_posts (post_type_id)');
        $this->addSql('CREATE INDEX IDX_DEDC9B1AE2532148 ON core_posts (featured_media_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_terms (
                  post_id INT NOT NULL,
                  taxonomy_term_id INT NOT NULL,
                  PRIMARY KEY (post_id, taxonomy_term_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_23F5377F4B89032C ON core_post_terms (post_id)');
        $this->addSql('CREATE INDEX IDX_23F5377F898CA496 ON core_post_terms (taxonomy_term_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_post_related_posts (
                  post_id INT NOT NULL,
                  related_post_id INT NOT NULL,
                  PRIMARY KEY (post_id, related_post_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_C8C5964C4B89032C ON core_post_related_posts (post_id)');
        $this->addSql('CREATE INDEX IDX_C8C5964C7490C989 ON core_post_related_posts (related_post_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_columns (
                  reference VARCHAR(64) DEFAULT NULL,
                  label VARCHAR(100) NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  project_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_472C0E8AAEA34913 ON core_project_columns (reference)');
        $this->addSql('CREATE INDEX IDX_472C0E8A166D1F9C ON core_project_columns (project_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_labels (
                  name VARCHAR(60) NOT NULL,
                  color VARCHAR(20) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  project_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_592900D4166D1F9C ON core_project_labels (project_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_saved_views (
                  name VARCHAR(100) NOT NULL,
                  filters JSON NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  owner_id INT NOT NULL,
                  project_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_BEC376367E3C61F9 ON core_project_saved_views (owner_id)');
        $this->addSql('CREATE INDEX IDX_BEC37636166D1F9C ON core_project_saved_views (project_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_sprints (
                  name VARCHAR(100) NOT NULL,
                  start_date DATE DEFAULT NULL,
                  end_date DATE DEFAULT NULL,
                  is_active BOOLEAN DEFAULT false NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  project_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_A506A74C166D1F9C ON core_project_sprints (project_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_task_comments (
                  content TEXT NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  author_id INT NOT NULL,
                  task_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_285E885DF675F31B ON core_project_task_comments (author_id)');
        $this->addSql('CREATE INDEX IDX_285E885D8DB60186 ON core_project_task_comments (task_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_task_items (
                  label VARCHAR(255) NOT NULL,
                  done BOOLEAN DEFAULT false NOT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  task_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_1174FDD18DB60186 ON core_project_task_items (task_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_task_time_entries (
                  minutes INT NOT NULL,
                  note TEXT DEFAULT NULL,
                  logged_at DATE NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  task_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_9CB4372DA76ED395 ON core_project_task_time_entries (user_id)');
        $this->addSql('CREATE INDEX IDX_9CB4372D8DB60186 ON core_project_task_time_entries (task_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_tasks (
                  reference VARCHAR(64) DEFAULT NULL,
                  title VARCHAR(255) NOT NULL,
                  description TEXT DEFAULT NULL,
                  priority VARCHAR(20) DEFAULT 'medium' NOT NULL,
                  due_date DATE DEFAULT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  story_points INT DEFAULT NULL,
                  estimate_minutes INT DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  project_id INT NOT NULL,
                  column_id INT NOT NULL,
                  assignee_id INT DEFAULT NULL,
                  sprint_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CBA88656AEA34913 ON core_project_tasks (reference)');
        $this->addSql('CREATE INDEX IDX_CBA88656166D1F9C ON core_project_tasks (project_id)');
        $this->addSql('CREATE INDEX IDX_CBA88656BE8E8ED5 ON core_project_tasks (column_id)');
        $this->addSql('CREATE INDEX IDX_CBA8865659EC7D60 ON core_project_tasks (assignee_id)');
        $this->addSql('CREATE INDEX IDX_CBA886568C24077B ON core_project_tasks (sprint_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_task_labels (
                  task_id INT NOT NULL,
                  label_id INT NOT NULL,
                  PRIMARY KEY (task_id, label_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_4C98B76A8DB60186 ON core_project_task_labels (task_id)');
        $this->addSql('CREATE INDEX IDX_4C98B76A33B92F39 ON core_project_task_labels (label_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_task_attachments (
                  task_id INT NOT NULL,
                  media_id INT NOT NULL,
                  PRIMARY KEY (task_id, media_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_595F791B8DB60186 ON core_project_task_attachments (task_id)');
        $this->addSql('CREATE INDEX IDX_595F791BEA9FDD75 ON core_project_task_attachments (media_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_task_watchers (
                  task_id INT NOT NULL,
                  user_id INT NOT NULL,
                  PRIMARY KEY (task_id, user_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_B31CB1598DB60186 ON core_project_task_watchers (task_id)');
        $this->addSql('CREATE INDEX IDX_B31CB159A76ED395 ON core_project_task_watchers (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_projects (
                  reference VARCHAR(64) DEFAULT NULL,
                  title VARCHAR(255) NOT NULL,
                  description TEXT DEFAULT NULL,
                  status VARCHAR(20) DEFAULT 'draft' NOT NULL,
                  start_date DATE DEFAULT NULL,
                  end_date DATE DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  responsible_user_id INT DEFAULT NULL,
                  crm_company_id INT DEFAULT NULL,
                  crm_deal_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E351C507AEA34913 ON core_projects (reference)');
        $this->addSql('CREATE INDEX IDX_E351C507BDAD1998 ON core_projects (responsible_user_id)');
        $this->addSql('CREATE INDEX IDX_E351C507D2052C5E ON core_projects (crm_company_id)');
        $this->addSql('CREATE INDEX IDX_E351C5071A456F92 ON core_projects (crm_deal_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_project_crm_contacts (
                  project_id INT NOT NULL,
                  contact_id INT NOT NULL,
                  PRIMARY KEY (project_id, contact_id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_85089333166D1F9C ON core_project_crm_contacts (project_id)');
        $this->addSql('CREATE INDEX IDX_85089333E7A1254A ON core_project_crm_contacts (contact_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_reset_password_requests (
                  reference VARCHAR(64) DEFAULT NULL,
                  selector VARCHAR(100) NOT NULL,
                  hashed_token VARCHAR(100) NOT NULL,
                  expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A837F08CAEA34913 ON core_reset_password_requests (reference)');
        $this->addSql('CREATE INDEX IDX_A837F08CA76ED395 ON core_reset_password_requests (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_services (
                  name VARCHAR(150) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_settings (
                  value TEXT DEFAULT NULL,
                  description VARCHAR(255) DEFAULT NULL,
                  setting_type VARCHAR(50) NOT NULL,
                  setting_group VARCHAR(100) DEFAULT NULL,
                  setting_key VARCHAR(100) NOT NULL,
                  PRIMARY KEY (setting_key)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_taxonomies (
                  slug VARCHAR(100) NOT NULL,
                  hierarchical BOOLEAN NOT NULL,
                  is_built_in BOOLEAN NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1F670418989D9B62 ON core_taxonomies (slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_taxonomy_term_translations (
                  locale VARCHAR(10) NOT NULL,
                  name VARCHAR(150) NOT NULL,
                  slug VARCHAR(180) NOT NULL,
                  description TEXT DEFAULT NULL,
                  id INT NOT NULL,
                  term_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_9E598575E2C35FC ON core_taxonomy_term_translations (term_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX UNIQ_9E598575E2C35FC4180C698 ON core_taxonomy_term_translations (term_id, locale)
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_term_locale_slug ON core_taxonomy_term_translations (locale, slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_taxonomy_terms (
                  reference VARCHAR(64) DEFAULT NULL,
                  position INT DEFAULT 0 NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  taxonomy_id INT NOT NULL,
                  parent_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_26991253AEA34913 ON core_taxonomy_terms (reference)');
        $this->addSql('CREATE INDEX IDX_269912539557E6F6 ON core_taxonomy_terms (taxonomy_id)');
        $this->addSql('CREATE INDEX IDX_26991253727ACA70 ON core_taxonomy_terms (parent_id)');
        $this->addSql('CREATE INDEX IDX_taxonomy_term_taxonomy_parent ON core_taxonomy_terms (taxonomy_id, parent_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_taxonomy_translations (
                  locale VARCHAR(10) NOT NULL,
                  label VARCHAR(150) NOT NULL,
                  description VARCHAR(255) DEFAULT NULL,
                  id INT NOT NULL,
                  taxonomy_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_5EE2037B9557E6F6 ON core_taxonomy_translations (taxonomy_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX UNIQ_5EE2037B9557E6F64180C698 ON core_taxonomy_translations (taxonomy_id, locale)
            SQL);
        $this->addSql(<<<'SQL'
                CREATE TABLE core_themes (
                  slug VARCHAR(100) NOT NULL,
                  name VARCHAR(100) NOT NULL,
                  description TEXT DEFAULT NULL,
                  active BOOLEAN NOT NULL,
                  config JSON NOT NULL,
                  id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B51E5187989D9B62 ON core_themes (slug)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_users (
                  reference VARCHAR(64) DEFAULT NULL,
                  email VARCHAR(180) NOT NULL,
                  name VARCHAR(100) NOT NULL,
                  roles JSON NOT NULL,
                  privileges JSON NOT NULL,
                  disabled_modules JSON DEFAULT '[]' NOT NULL,
                  hidden_nav_sections JSON DEFAULT '[]' NOT NULL,
                  hidden_nav_items JSON DEFAULT '[]' NOT NULL,
                  nav_section_colors JSON DEFAULT '{}' NOT NULL,
                  password VARCHAR(255) NOT NULL,
                  locale VARCHAR(5) NOT NULL,
                  status VARCHAR(20) DEFAULT 'active' NOT NULL,
                  type VARCHAR(20) DEFAULT 'backend' NOT NULL,
                  profile_photo_path VARCHAR(255) DEFAULT NULL,
                  mood_message VARCHAR(160) DEFAULT NULL,
                  invitation_selector VARCHAR(20) DEFAULT NULL,
                  invitation_hashed_token VARCHAR(128) DEFAULT NULL,
                  invitation_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  invited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  email_verification_token VARCHAR(64) DEFAULT NULL,
                  email_verification_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  agency_id INT DEFAULT NULL,
                  service_id INT DEFAULT NULL,
                  manager_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42028409AEA34913 ON core_users (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42028409B6869AC0 ON core_users (invitation_selector)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42028409C4995C67 ON core_users (email_verification_token)');
        $this->addSql('CREATE INDEX IDX_42028409CDEADB2A ON core_users (agency_id)');
        $this->addSql('CREATE INDEX IDX_42028409ED5CA9E6 ON core_users (service_id)');
        $this->addSql('CREATE INDEX IDX_42028409783E3463 ON core_users (manager_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email_type ON core_users (email, type)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_vault_entries (
                  type VARCHAR(50) NOT NULL,
                  title VARCHAR(255) NOT NULL,
                  url VARCHAR(255) DEFAULT NULL,
                  encrypted_data TEXT NOT NULL,
                  iv VARCHAR(64) NOT NULL,
                  is_favorite BOOLEAN NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  folder_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_FB442AA4A76ED395 ON core_vault_entries (user_id)');
        $this->addSql('CREATE INDEX IDX_FB442AA4162CB942 ON core_vault_entries (folder_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_vault_folders (
                  name VARCHAR(100) NOT NULL,
                  color VARCHAR(7) DEFAULT NULL,
                  position INT NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  parent_id INT DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE INDEX IDX_288B4A6EA76ED395 ON core_vault_folders (user_id)');
        $this->addSql('CREATE INDEX IDX_288B4A6E727ACA70 ON core_vault_folders (parent_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE core_vault_user_configs (
                  argon2_salt VARCHAR(128) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  id INT NOT NULL,
                  user_id INT NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6558F307A76ED395 ON core_vault_user_configs (user_id)');
        $this->addSql(<<<'SQL'
                CREATE TABLE messenger_messages (
                  id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                  body TEXT NOT NULL,
                  headers TEXT NOT NULL,
                  queue_name VARCHAR(190) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql(<<<'SQL'
                CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (
                  queue_name, available_at, delivered_at,
                  id
                )
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_assistant_conversations
                ADD
                  CONSTRAINT FK_C2DBED58A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_assistant_conversations
                ADD
                  CONSTRAINT FK_C2DBED58CDEADB2A FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_assistant_messages
                ADD
                  CONSTRAINT FK_459728D9AC0396 FOREIGN KEY (conversation_id) REFERENCES core_assistant_conversations (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_assistant_mount_points
                ADD
                  CONSTRAINT FK_68CC5D23A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_invoice_lines
                ADD
                  CONSTRAINT FK_5C28EEC62989F1FD FOREIGN KEY (invoice_id) REFERENCES core_billing_invoices (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_invoices
                ADD
                  CONSTRAINT FK_173E636D68B77723 FOREIGN KEY (tiers_id) REFERENCES core_billing_tiers (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_invoices
                ADD
                  CONSTRAINT FK_173E636DD1710F83 FOREIGN KEY (buyer_tiers_id) REFERENCES core_billing_tiers (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_invoices
                ADD
                  CONSTRAINT FK_173E636D1C696F7A FOREIGN KEY (credit_note_id) REFERENCES core_billing_invoices (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_invoices
                ADD
                  CONSTRAINT FK_173E636DC33F7837 FOREIGN KEY (document_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_invoices
                ADD
                  CONSTRAINT FK_173E636D27426A53 FOREIGN KEY (ocr_job_id) REFERENCES core_billing_ocr_jobs (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_ocr_jobs
                ADD
                  CONSTRAINT FK_DED831F7B03A8386 FOREIGN KEY (created_by_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_ocr_jobs
                ADD
                  CONSTRAINT FK_DED831F7EA9FDD75 FOREIGN KEY (media_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_billing_tiers
                ADD
                  CONSTRAINT FK_9B9DF4D0979B1AD6 FOREIGN KEY (company_id) REFERENCES core_crm_companies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_block_notes
                ADD
                  CONSTRAINT FK_113BFE00A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_block_notes
                ADD
                  CONSTRAINT FK_113BFE00CDEADB2A FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_block_notes
                ADD
                  CONSTRAINT FK_113BFE00727ACA70 FOREIGN KEY (parent_id) REFERENCES core_block_notes (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_comment_reactions
                ADD
                  CONSTRAINT FK_D60597D9F8697D13 FOREIGN KEY (comment_id) REFERENCES core_comments (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_comments
                ADD
                  CONSTRAINT FK_E05CE0894B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_comments
                ADD
                  CONSTRAINT FK_E05CE089727ACA70 FOREIGN KEY (parent_id) REFERENCES core_comments (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_crm_contacts
                ADD
                  CONSTRAINT FK_F077E100979B1AD6 FOREIGN KEY (company_id) REFERENCES core_crm_companies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_crm_contact_tag_map
                ADD
                  CONSTRAINT FK_2C26A5FFE7A1254A FOREIGN KEY (contact_id) REFERENCES core_crm_contacts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_crm_contact_tag_map
                ADD
                  CONSTRAINT FK_2C26A5FF2A405490 FOREIGN KEY (contact_tag_id) REFERENCES core_crm_contact_tags (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_crm_deals
                ADD
                  CONSTRAINT FK_1C50EB87E7A1254A FOREIGN KEY (contact_id) REFERENCES core_crm_contacts (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_crm_deals
                ADD
                  CONSTRAINT FK_1C50EB87979B1AD6 FOREIGN KEY (company_id) REFERENCES core_crm_companies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_cart_items
                ADD
                  CONSTRAINT FK_BE453BB11AD5CDBF FOREIGN KEY (cart_id) REFERENCES core_ecommerce_carts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_cart_items
                ADD
                  CONSTRAINT FK_BE453BB1D4619D1A FOREIGN KEY (listing_id) REFERENCES core_ecommerce_listings (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_carts
                ADD
                  CONSTRAINT FK_349FCE79A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_categories
                ADD
                  CONSTRAINT FK_3015D435727ACA70 FOREIGN KEY (parent_id) REFERENCES core_ecommerce_listing_categories (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_categories
                ADD
                  CONSTRAINT FK_3015D4353DA5256D FOREIGN KEY (image_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_category_translations
                ADD
                  CONSTRAINT FK_B2F8D54B12469DE2 FOREIGN KEY (category_id) REFERENCES core_ecommerce_listing_categories (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_tag_translations
                ADD
                  CONSTRAINT FK_83F405EEBAD26311 FOREIGN KEY (tag_id) REFERENCES core_ecommerce_listing_tags (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listings
                ADD
                  CONSTRAINT FK_2E66FDB54584665A FOREIGN KEY (product_id) REFERENCES core_erp_products (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listings
                ADD
                  CONSTRAINT FK_2E66FDB53569D950 FOREIGN KEY (featured_image_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_category_map
                ADD
                  CONSTRAINT FK_A77BDC76D4619D1A FOREIGN KEY (listing_id) REFERENCES core_ecommerce_listings (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_category_map
                ADD
                  CONSTRAINT FK_A77BDC76455844B0 FOREIGN KEY (listing_category_id) REFERENCES core_ecommerce_listing_categories (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_tag_map
                ADD
                  CONSTRAINT FK_B1747780D4619D1A FOREIGN KEY (listing_id) REFERENCES core_ecommerce_listings (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_listing_tag_map
                ADD
                  CONSTRAINT FK_B17477805E2A42C2 FOREIGN KEY (listing_tag_id) REFERENCES core_ecommerce_listing_tags (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_order_lines
                ADD
                  CONSTRAINT FK_764F7FD18D9F6D38 FOREIGN KEY (order_id) REFERENCES core_ecommerce_orders (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_order_lines
                ADD
                  CONSTRAINT FK_764F7FD1D4619D1A FOREIGN KEY (listing_id) REFERENCES core_ecommerce_listings (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ecommerce_orders
                ADD
                  CONSTRAINT FK_13EC44319395C3F3 FOREIGN KEY (customer_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_employees
                ADD
                  CONSTRAINT FK_F5E2F324A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_employees
                ADD
                  CONSTRAINT FK_F5E2F324ED5CA9E6 FOREIGN KEY (service_id) REFERENCES core_services (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_employees
                ADD
                  CONSTRAINT FK_F5E2F324CDEADB2A FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_erp_products
                ADD
                  CONSTRAINT FK_4DE001913DA5256D FOREIGN KEY (image_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_form_field_translations
                ADD
                  CONSTRAINT FK_490A58F5443707B0 FOREIGN KEY (field_id) REFERENCES core_form_fields (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_form_fields
                ADD
                  CONSTRAINT FK_AB3AA94C5FF69B7D FOREIGN KEY (form_id) REFERENCES core_forms (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_form_submissions
                ADD
                  CONSTRAINT FK_ADC7DCE25FF69B7D FOREIGN KEY (form_id) REFERENCES core_forms (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_form_translations
                ADD
                  CONSTRAINT FK_ABF898D95FF69B7D FOREIGN KEY (form_id) REFERENCES core_forms (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_document_folders
                ADD
                  CONSTRAINT FK_B0EE1B81727ACA70 FOREIGN KEY (parent_id) REFERENCES core_ged_document_folders (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_document_versions
                ADD
                  CONSTRAINT FK_1392373DC33F7837 FOREIGN KEY (document_id) REFERENCES core_ged_documents (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_document_versions
                ADD
                  CONSTRAINT FK_1392373D93CB796C FOREIGN KEY (file_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_documents
                ADD
                  CONSTRAINT FK_A80B359A12469DE2 FOREIGN KEY (category_id) REFERENCES core_ged_document_categories (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_documents
                ADD
                  CONSTRAINT FK_A80B359A93CB796C FOREIGN KEY (file_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_documents
                ADD
                  CONSTRAINT FK_A80B359A162CB942 FOREIGN KEY (folder_id) REFERENCES core_ged_document_folders (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_document_tag_map
                ADD
                  CONSTRAINT FK_DCAAF837C33F7837 FOREIGN KEY (document_id) REFERENCES core_ged_documents (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_ged_document_tag_map
                ADD
                  CONSTRAINT FK_DCAAF8374B0D277 FOREIGN KEY (document_tag_id) REFERENCES core_ged_document_tags (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_markdown_notes
                ADD
                  CONSTRAINT FK_650ACC0BA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_markdown_notes
                ADD
                  CONSTRAINT FK_650ACC0BCDEADB2A FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_markdown_notes
                ADD
                  CONSTRAINT FK_650ACC0B727ACA70 FOREIGN KEY (parent_id) REFERENCES core_markdown_notes (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_media
                ADD
                  CONSTRAINT FK_3CAD80ECA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_media
                ADD
                  CONSTRAINT FK_3CAD80EC162CB942 FOREIGN KEY (folder_id) REFERENCES core_media_folders (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_media_folders
                ADD
                  CONSTRAINT FK_1745BF19727ACA70 FOREIGN KEY (parent_id) REFERENCES core_media_folders (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_menu_item_translations
                ADD
                  CONSTRAINT FK_7C582A479AB44FE0 FOREIGN KEY (menu_item_id) REFERENCES core_menu_items (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_menu_items
                ADD
                  CONSTRAINT FK_4CFE4ECB727ACA70 FOREIGN KEY (parent_id) REFERENCES core_menu_items (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_menu_items
                ADD
                  CONSTRAINT FK_4CFE4ECBCCD7E912 FOREIGN KEY (menu_id) REFERENCES core_menus (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_notifications
                ADD
                  CONSTRAINT FK_E8A55A8CE92F8F78 FOREIGN KEY (recipient_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget
                ADD
                  CONSTRAINT FK_81763E59A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget
                ADD
                  CONSTRAINT FK_81763E59712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget_item
                ADD
                  CONSTRAINT FK_152DCAC536ABA6B8 FOREIGN KEY (budget_id) REFERENCES core_personal_finance_budget (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget_item
                ADD
                  CONSTRAINT FK_152DCAC512469DE2 FOREIGN KEY (category_id) REFERENCES core_personal_finance_category (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget_preset
                ADD
                  CONSTRAINT FK_7E260D39A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget_preset
                ADD
                  CONSTRAINT FK_7E260D39712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget_preset_item
                ADD
                  CONSTRAINT FK_9119390580688E6F FOREIGN KEY (preset_id) REFERENCES core_personal_finance_budget_preset (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_budget_preset_item
                ADD
                  CONSTRAINT FK_9119390512469DE2 FOREIGN KEY (category_id) REFERENCES core_personal_finance_category (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_categorization_rule
                ADD
                  CONSTRAINT FK_3DF8CF0CA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_categorization_rule
                ADD
                  CONSTRAINT FK_3DF8CF0C12469DE2 FOREIGN KEY (category_id) REFERENCES core_personal_finance_category (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_category
                ADD
                  CONSTRAINT FK_4346D771A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_category
                ADD
                  CONSTRAINT FK_4346D771712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_goal
                ADD
                  CONSTRAINT FK_56ECB961A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_goal
                ADD
                  CONSTRAINT FK_56ECB961712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_goal
                ADD
                  CONSTRAINT FK_56ECB96112469DE2 FOREIGN KEY (category_id) REFERENCES core_personal_finance_category (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_recurring_transaction
                ADD
                  CONSTRAINT FK_57EACDF0A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_recurring_transaction
                ADD
                  CONSTRAINT FK_57EACDF0712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_recurring_transaction
                ADD
                  CONSTRAINT FK_57EACDF012469DE2 FOREIGN KEY (category_id) REFERENCES core_personal_finance_category (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_scheduled_transaction
                ADD
                  CONSTRAINT FK_44AE2B27A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_scheduled_transaction
                ADD
                  CONSTRAINT FK_44AE2B27712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_scheduled_transaction
                ADD
                  CONSTRAINT FK_44AE2B2712469DE2 FOREIGN KEY (category_id) REFERENCES core_personal_finance_category (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_transaction
                ADD
                  CONSTRAINT FK_2C5E85AA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_transaction
                ADD
                  CONSTRAINT FK_2C5E85A712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_transaction
                ADD
                  CONSTRAINT FK_2C5E85A12469DE2 FOREIGN KEY (category_id) REFERENCES core_personal_finance_category (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_wallet
                ADD
                  CONSTRAINT FK_8EEC5B3D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_wallet_invitation
                ADD
                  CONSTRAINT FK_AAEF0F69A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_wallet_invitation
                ADD
                  CONSTRAINT FK_AAEF0F69712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_wallet_member
                ADD
                  CONSTRAINT FK_C688CA8BA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_personal_finance_wallet_member
                ADD
                  CONSTRAINT FK_C688CA8B712520F3 FOREIGN KEY (wallet_id) REFERENCES core_personal_finance_wallet (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_galleries
                ADD
                  CONSTRAINT FK_870CFACEB03A8386 FOREIGN KEY (created_by_id) REFERENCES core_users (id) ON DELETE RESTRICT NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_galleries
                ADD
                  CONSTRAINT FK_870CFACE329A1B2E FOREIGN KEY (cover_media_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_galleries
                ADD
                  CONSTRAINT FK_870CFACE77F5180B FOREIGN KEY (client_contact_id) REFERENCES core_crm_contacts (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_gallery_finalizations
                ADD
                  CONSTRAINT FK_6935614F4E7AF8F FOREIGN KEY (gallery_id) REFERENCES core_photo_galleries (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_gallery_invites
                ADD
                  CONSTRAINT FK_CD5756464E7AF8F FOREIGN KEY (gallery_id) REFERENCES core_photo_galleries (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_gallery_item_comments
                ADD
                  CONSTRAINT FK_B77E76C82A151376 FOREIGN KEY (gallery_item_id) REFERENCES core_photo_gallery_items (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_gallery_items
                ADD
                  CONSTRAINT FK_8B9DD5EA4E7AF8F FOREIGN KEY (gallery_id) REFERENCES core_photo_galleries (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_gallery_items
                ADD
                  CONSTRAINT FK_8B9DD5EAEA9FDD75 FOREIGN KEY (media_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_photo_gallery_picks
                ADD
                  CONSTRAINT FK_16C9746F2A151376 FOREIGN KEY (gallery_item_id) REFERENCES core_photo_gallery_items (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_planning_events
                ADD
                  CONSTRAINT FK_4E5AB77D3D865311 FOREIGN KEY (planning_id) REFERENCES core_plannings (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_planning_event_attendees
                ADD
                  CONSTRAINT FK_F280AEA271F7E88B FOREIGN KEY (event_id) REFERENCES core_planning_events (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_planning_event_attendees
                ADD
                  CONSTRAINT FK_F280AEA2A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_plannings
                ADD
                  CONSTRAINT FK_6431B97E3C61F9 FOREIGN KEY (owner_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_plannings
                ADD
                  CONSTRAINT FK_6431B9CDEADB2A FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_it_notes
                ADD
                  CONSTRAINT FK_BF08188CA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_it_notes
                ADD
                  CONSTRAINT FK_BF08188CCDEADB2A FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_revisions
                ADD
                  CONSTRAINT FK_D383B5FDF675F31B FOREIGN KEY (author_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_revisions
                ADD
                  CONSTRAINT FK_D383B5FD4B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_slug_history
                ADD
                  CONSTRAINT FK_C04809954B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_translations
                ADD
                  CONSTRAINT FK_6A82AE686EFCB8B8 FOREIGN KEY (og_image_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_translations
                ADD
                  CONSTRAINT FK_6A82AE684B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_type_fields
                ADD
                  CONSTRAINT FK_D4BF279BF8A43BA0 FOREIGN KEY (post_type_id) REFERENCES core_post_types (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_type_taxonomies
                ADD
                  CONSTRAINT FK_8176B93CF8A43BA0 FOREIGN KEY (post_type_id) REFERENCES core_post_types (id) NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_type_taxonomies
                ADD
                  CONSTRAINT FK_8176B93C9557E6F6 FOREIGN KEY (taxonomy_id) REFERENCES core_taxonomies (id) NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_posts
                ADD
                  CONSTRAINT FK_DEDC9B1AF675F31B FOREIGN KEY (author_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_posts
                ADD
                  CONSTRAINT FK_DEDC9B1AF8A43BA0 FOREIGN KEY (post_type_id) REFERENCES core_post_types (id) NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_posts
                ADD
                  CONSTRAINT FK_DEDC9B1AE2532148 FOREIGN KEY (featured_media_id) REFERENCES core_media (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_terms
                ADD
                  CONSTRAINT FK_23F5377F4B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_terms
                ADD
                  CONSTRAINT FK_23F5377F898CA496 FOREIGN KEY (taxonomy_term_id) REFERENCES core_taxonomy_terms (id) NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_related_posts
                ADD
                  CONSTRAINT FK_C8C5964C4B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_post_related_posts
                ADD
                  CONSTRAINT FK_C8C5964C7490C989 FOREIGN KEY (related_post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_columns
                ADD
                  CONSTRAINT FK_472C0E8A166D1F9C FOREIGN KEY (project_id) REFERENCES core_projects (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_labels
                ADD
                  CONSTRAINT FK_592900D4166D1F9C FOREIGN KEY (project_id) REFERENCES core_projects (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_saved_views
                ADD
                  CONSTRAINT FK_BEC376367E3C61F9 FOREIGN KEY (owner_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_saved_views
                ADD
                  CONSTRAINT FK_BEC37636166D1F9C FOREIGN KEY (project_id) REFERENCES core_projects (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_sprints
                ADD
                  CONSTRAINT FK_A506A74C166D1F9C FOREIGN KEY (project_id) REFERENCES core_projects (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_comments
                ADD
                  CONSTRAINT FK_285E885DF675F31B FOREIGN KEY (author_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_comments
                ADD
                  CONSTRAINT FK_285E885D8DB60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_items
                ADD
                  CONSTRAINT FK_1174FDD18DB60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_time_entries
                ADD
                  CONSTRAINT FK_9CB4372DA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_time_entries
                ADD
                  CONSTRAINT FK_9CB4372D8DB60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_tasks
                ADD
                  CONSTRAINT FK_CBA88656166D1F9C FOREIGN KEY (project_id) REFERENCES core_projects (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_tasks
                ADD
                  CONSTRAINT FK_CBA88656BE8E8ED5 FOREIGN KEY (column_id) REFERENCES core_project_columns (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_tasks
                ADD
                  CONSTRAINT FK_CBA8865659EC7D60 FOREIGN KEY (assignee_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_tasks
                ADD
                  CONSTRAINT FK_CBA886568C24077B FOREIGN KEY (sprint_id) REFERENCES core_project_sprints (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_labels
                ADD
                  CONSTRAINT FK_4C98B76A8DB60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_labels
                ADD
                  CONSTRAINT FK_4C98B76A33B92F39 FOREIGN KEY (label_id) REFERENCES core_project_labels (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_attachments
                ADD
                  CONSTRAINT FK_595F791B8DB60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_attachments
                ADD
                  CONSTRAINT FK_595F791BEA9FDD75 FOREIGN KEY (media_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_watchers
                ADD
                  CONSTRAINT FK_B31CB1598DB60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_task_watchers
                ADD
                  CONSTRAINT FK_B31CB159A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_projects
                ADD
                  CONSTRAINT FK_E351C507BDAD1998 FOREIGN KEY (responsible_user_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_projects
                ADD
                  CONSTRAINT FK_E351C507D2052C5E FOREIGN KEY (crm_company_id) REFERENCES core_crm_companies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_projects
                ADD
                  CONSTRAINT FK_E351C5071A456F92 FOREIGN KEY (crm_deal_id) REFERENCES core_crm_deals (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_crm_contacts
                ADD
                  CONSTRAINT FK_85089333166D1F9C FOREIGN KEY (project_id) REFERENCES core_projects (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_project_crm_contacts
                ADD
                  CONSTRAINT FK_85089333E7A1254A FOREIGN KEY (contact_id) REFERENCES core_crm_contacts (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_reset_password_requests
                ADD
                  CONSTRAINT FK_A837F08CA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_taxonomy_term_translations
                ADD
                  CONSTRAINT FK_9E598575E2C35FC FOREIGN KEY (term_id) REFERENCES core_taxonomy_terms (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_taxonomy_terms
                ADD
                  CONSTRAINT FK_269912539557E6F6 FOREIGN KEY (taxonomy_id) REFERENCES core_taxonomies (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_taxonomy_terms
                ADD
                  CONSTRAINT FK_26991253727ACA70 FOREIGN KEY (parent_id) REFERENCES core_taxonomy_terms (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_taxonomy_translations
                ADD
                  CONSTRAINT FK_5EE2037B9557E6F6 FOREIGN KEY (taxonomy_id) REFERENCES core_taxonomies (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_users
                ADD
                  CONSTRAINT FK_42028409CDEADB2A FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_users
                ADD
                  CONSTRAINT FK_42028409ED5CA9E6 FOREIGN KEY (service_id) REFERENCES core_services (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_users
                ADD
                  CONSTRAINT FK_42028409783E3463 FOREIGN KEY (manager_id) REFERENCES core_users (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_vault_entries
                ADD
                  CONSTRAINT FK_FB442AA4A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_vault_entries
                ADD
                  CONSTRAINT FK_FB442AA4162CB942 FOREIGN KEY (folder_id) REFERENCES core_vault_folders (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_vault_folders
                ADD
                  CONSTRAINT FK_288B4A6EA76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_vault_folders
                ADD
                  CONSTRAINT FK_288B4A6E727ACA70 FOREIGN KEY (parent_id) REFERENCES core_vault_folders (id) ON DELETE
                SET
                  NULL NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  core_vault_user_configs
                ADD
                  CONSTRAINT FK_6558F307A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE seq_core_access_request_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_agency_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_assistant_conversation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_assistant_message_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_assistant_mount_point_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_audit_log_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_invoice_line_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_invoice_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_ocr_job_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_tiers_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_block_note_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_comment_reaction_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_comment_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_company_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_contact_tag_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_contact_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_deal_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_cart_item_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_cart_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_listing_category_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_listing_category_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_listing_tag_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_listing_tag_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_listing_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_order_line_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_order_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_employee_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_product_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_form_field_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_form_field_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_form_submission_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_form_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_form_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_ged_category_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_ged_document_folder_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_ged_document_tag_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_ged_document_version_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_ged_document_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_markdown_note_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_media_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_media_folder_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_menu_item_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_menu_item_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_menu_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_mount_point_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_notification_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_budget_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_budget_item_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_budget_preset_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_budget_preset_item_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_categorization_rule_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_category_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_goal_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_recurring_transaction_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_scheduled_transaction_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_transaction_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_wallet_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_wallet_invitation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_personal_finance_wallet_member_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_gallery_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_gallery_finalization_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_gallery_invite_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_gallery_item_comment_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_gallery_item_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_gallery_pick_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_planning_event_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_planning_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_post_it_note_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_post_revision_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_post_slug_history_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_post_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_post_type_field_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_post_type_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_post_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_column_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_label_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_saved_view_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_sprint_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_task_comment_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_task_item_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_task_time_entry_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_task_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_core_project_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_reset_password_request_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_service_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_taxonomy_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_taxonomy_term_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_taxonomy_term_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_taxonomy_translation_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_theme_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_user_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_vault_entry_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_vault_folder_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_vault_user_config_id CASCADE');
        $this->addSql('ALTER TABLE core_assistant_conversations DROP CONSTRAINT FK_C2DBED58A76ED395');
        $this->addSql('ALTER TABLE core_assistant_conversations DROP CONSTRAINT FK_C2DBED58CDEADB2A');
        $this->addSql('ALTER TABLE core_assistant_messages DROP CONSTRAINT FK_459728D9AC0396');
        $this->addSql('ALTER TABLE core_assistant_mount_points DROP CONSTRAINT FK_68CC5D23A76ED395');
        $this->addSql('ALTER TABLE core_billing_invoice_lines DROP CONSTRAINT FK_5C28EEC62989F1FD');
        $this->addSql('ALTER TABLE core_billing_invoices DROP CONSTRAINT FK_173E636D68B77723');
        $this->addSql('ALTER TABLE core_billing_invoices DROP CONSTRAINT FK_173E636DD1710F83');
        $this->addSql('ALTER TABLE core_billing_invoices DROP CONSTRAINT FK_173E636D1C696F7A');
        $this->addSql('ALTER TABLE core_billing_invoices DROP CONSTRAINT FK_173E636DC33F7837');
        $this->addSql('ALTER TABLE core_billing_invoices DROP CONSTRAINT FK_173E636D27426A53');
        $this->addSql('ALTER TABLE core_billing_ocr_jobs DROP CONSTRAINT FK_DED831F7B03A8386');
        $this->addSql('ALTER TABLE core_billing_ocr_jobs DROP CONSTRAINT FK_DED831F7EA9FDD75');
        $this->addSql('ALTER TABLE core_billing_tiers DROP CONSTRAINT FK_9B9DF4D0979B1AD6');
        $this->addSql('ALTER TABLE core_block_notes DROP CONSTRAINT FK_113BFE00A76ED395');
        $this->addSql('ALTER TABLE core_block_notes DROP CONSTRAINT FK_113BFE00CDEADB2A');
        $this->addSql('ALTER TABLE core_block_notes DROP CONSTRAINT FK_113BFE00727ACA70');
        $this->addSql('ALTER TABLE core_comment_reactions DROP CONSTRAINT FK_D60597D9F8697D13');
        $this->addSql('ALTER TABLE core_comments DROP CONSTRAINT FK_E05CE0894B89032C');
        $this->addSql('ALTER TABLE core_comments DROP CONSTRAINT FK_E05CE089727ACA70');
        $this->addSql('ALTER TABLE core_crm_contacts DROP CONSTRAINT FK_F077E100979B1AD6');
        $this->addSql('ALTER TABLE core_crm_contact_tag_map DROP CONSTRAINT FK_2C26A5FFE7A1254A');
        $this->addSql('ALTER TABLE core_crm_contact_tag_map DROP CONSTRAINT FK_2C26A5FF2A405490');
        $this->addSql('ALTER TABLE core_crm_deals DROP CONSTRAINT FK_1C50EB87E7A1254A');
        $this->addSql('ALTER TABLE core_crm_deals DROP CONSTRAINT FK_1C50EB87979B1AD6');
        $this->addSql('ALTER TABLE core_ecommerce_cart_items DROP CONSTRAINT FK_BE453BB11AD5CDBF');
        $this->addSql('ALTER TABLE core_ecommerce_cart_items DROP CONSTRAINT FK_BE453BB1D4619D1A');
        $this->addSql('ALTER TABLE core_ecommerce_carts DROP CONSTRAINT FK_349FCE79A76ED395');
        $this->addSql('ALTER TABLE core_ecommerce_listing_categories DROP CONSTRAINT FK_3015D435727ACA70');
        $this->addSql('ALTER TABLE core_ecommerce_listing_categories DROP CONSTRAINT FK_3015D4353DA5256D');
        $this->addSql('ALTER TABLE core_ecommerce_listing_category_translations DROP CONSTRAINT FK_B2F8D54B12469DE2');
        $this->addSql('ALTER TABLE core_ecommerce_listing_tag_translations DROP CONSTRAINT FK_83F405EEBAD26311');
        $this->addSql('ALTER TABLE core_ecommerce_listings DROP CONSTRAINT FK_2E66FDB54584665A');
        $this->addSql('ALTER TABLE core_ecommerce_listings DROP CONSTRAINT FK_2E66FDB53569D950');
        $this->addSql('ALTER TABLE core_ecommerce_listing_category_map DROP CONSTRAINT FK_A77BDC76D4619D1A');
        $this->addSql('ALTER TABLE core_ecommerce_listing_category_map DROP CONSTRAINT FK_A77BDC76455844B0');
        $this->addSql('ALTER TABLE core_ecommerce_listing_tag_map DROP CONSTRAINT FK_B1747780D4619D1A');
        $this->addSql('ALTER TABLE core_ecommerce_listing_tag_map DROP CONSTRAINT FK_B17477805E2A42C2');
        $this->addSql('ALTER TABLE core_ecommerce_order_lines DROP CONSTRAINT FK_764F7FD18D9F6D38');
        $this->addSql('ALTER TABLE core_ecommerce_order_lines DROP CONSTRAINT FK_764F7FD1D4619D1A');
        $this->addSql('ALTER TABLE core_ecommerce_orders DROP CONSTRAINT FK_13EC44319395C3F3');
        $this->addSql('ALTER TABLE core_employees DROP CONSTRAINT FK_F5E2F324A76ED395');
        $this->addSql('ALTER TABLE core_employees DROP CONSTRAINT FK_F5E2F324ED5CA9E6');
        $this->addSql('ALTER TABLE core_employees DROP CONSTRAINT FK_F5E2F324CDEADB2A');
        $this->addSql('ALTER TABLE core_erp_products DROP CONSTRAINT FK_4DE001913DA5256D');
        $this->addSql('ALTER TABLE core_form_field_translations DROP CONSTRAINT FK_490A58F5443707B0');
        $this->addSql('ALTER TABLE core_form_fields DROP CONSTRAINT FK_AB3AA94C5FF69B7D');
        $this->addSql('ALTER TABLE core_form_submissions DROP CONSTRAINT FK_ADC7DCE25FF69B7D');
        $this->addSql('ALTER TABLE core_form_translations DROP CONSTRAINT FK_ABF898D95FF69B7D');
        $this->addSql('ALTER TABLE core_ged_document_folders DROP CONSTRAINT FK_B0EE1B81727ACA70');
        $this->addSql('ALTER TABLE core_ged_document_versions DROP CONSTRAINT FK_1392373DC33F7837');
        $this->addSql('ALTER TABLE core_ged_document_versions DROP CONSTRAINT FK_1392373D93CB796C');
        $this->addSql('ALTER TABLE core_ged_documents DROP CONSTRAINT FK_A80B359A12469DE2');
        $this->addSql('ALTER TABLE core_ged_documents DROP CONSTRAINT FK_A80B359A93CB796C');
        $this->addSql('ALTER TABLE core_ged_documents DROP CONSTRAINT FK_A80B359A162CB942');
        $this->addSql('ALTER TABLE core_ged_document_tag_map DROP CONSTRAINT FK_DCAAF837C33F7837');
        $this->addSql('ALTER TABLE core_ged_document_tag_map DROP CONSTRAINT FK_DCAAF8374B0D277');
        $this->addSql('ALTER TABLE core_markdown_notes DROP CONSTRAINT FK_650ACC0BA76ED395');
        $this->addSql('ALTER TABLE core_markdown_notes DROP CONSTRAINT FK_650ACC0BCDEADB2A');
        $this->addSql('ALTER TABLE core_markdown_notes DROP CONSTRAINT FK_650ACC0B727ACA70');
        $this->addSql('ALTER TABLE core_media DROP CONSTRAINT FK_3CAD80ECA2B28FE8');
        $this->addSql('ALTER TABLE core_media DROP CONSTRAINT FK_3CAD80EC162CB942');
        $this->addSql('ALTER TABLE core_media_folders DROP CONSTRAINT FK_1745BF19727ACA70');
        $this->addSql('ALTER TABLE core_menu_item_translations DROP CONSTRAINT FK_7C582A479AB44FE0');
        $this->addSql('ALTER TABLE core_menu_items DROP CONSTRAINT FK_4CFE4ECB727ACA70');
        $this->addSql('ALTER TABLE core_menu_items DROP CONSTRAINT FK_4CFE4ECBCCD7E912');
        $this->addSql('ALTER TABLE core_notifications DROP CONSTRAINT FK_E8A55A8CE92F8F78');
        $this->addSql('ALTER TABLE core_personal_finance_budget DROP CONSTRAINT FK_81763E59A76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_budget DROP CONSTRAINT FK_81763E59712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_budget_item DROP CONSTRAINT FK_152DCAC536ABA6B8');
        $this->addSql('ALTER TABLE core_personal_finance_budget_item DROP CONSTRAINT FK_152DCAC512469DE2');
        $this->addSql('ALTER TABLE core_personal_finance_budget_preset DROP CONSTRAINT FK_7E260D39A76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_budget_preset DROP CONSTRAINT FK_7E260D39712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_budget_preset_item DROP CONSTRAINT FK_9119390580688E6F');
        $this->addSql('ALTER TABLE core_personal_finance_budget_preset_item DROP CONSTRAINT FK_9119390512469DE2');
        $this->addSql('ALTER TABLE core_personal_finance_categorization_rule DROP CONSTRAINT FK_3DF8CF0CA76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_categorization_rule DROP CONSTRAINT FK_3DF8CF0C12469DE2');
        $this->addSql('ALTER TABLE core_personal_finance_category DROP CONSTRAINT FK_4346D771A76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_category DROP CONSTRAINT FK_4346D771712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_goal DROP CONSTRAINT FK_56ECB961A76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_goal DROP CONSTRAINT FK_56ECB961712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_goal DROP CONSTRAINT FK_56ECB96112469DE2');
        $this->addSql('ALTER TABLE core_personal_finance_recurring_transaction DROP CONSTRAINT FK_57EACDF0A76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_recurring_transaction DROP CONSTRAINT FK_57EACDF0712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_recurring_transaction DROP CONSTRAINT FK_57EACDF012469DE2');
        $this->addSql('ALTER TABLE core_personal_finance_scheduled_transaction DROP CONSTRAINT FK_44AE2B27A76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_scheduled_transaction DROP CONSTRAINT FK_44AE2B27712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_scheduled_transaction DROP CONSTRAINT FK_44AE2B2712469DE2');
        $this->addSql('ALTER TABLE core_personal_finance_transaction DROP CONSTRAINT FK_2C5E85AA76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_transaction DROP CONSTRAINT FK_2C5E85A712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_transaction DROP CONSTRAINT FK_2C5E85A12469DE2');
        $this->addSql('ALTER TABLE core_personal_finance_wallet DROP CONSTRAINT FK_8EEC5B3D7E3C61F9');
        $this->addSql('ALTER TABLE core_personal_finance_wallet_invitation DROP CONSTRAINT FK_AAEF0F69A7B4A7E3');
        $this->addSql('ALTER TABLE core_personal_finance_wallet_invitation DROP CONSTRAINT FK_AAEF0F69712520F3');
        $this->addSql('ALTER TABLE core_personal_finance_wallet_member DROP CONSTRAINT FK_C688CA8BA76ED395');
        $this->addSql('ALTER TABLE core_personal_finance_wallet_member DROP CONSTRAINT FK_C688CA8B712520F3');
        $this->addSql('ALTER TABLE core_photo_galleries DROP CONSTRAINT FK_870CFACEB03A8386');
        $this->addSql('ALTER TABLE core_photo_galleries DROP CONSTRAINT FK_870CFACE329A1B2E');
        $this->addSql('ALTER TABLE core_photo_galleries DROP CONSTRAINT FK_870CFACE77F5180B');
        $this->addSql('ALTER TABLE core_photo_gallery_finalizations DROP CONSTRAINT FK_6935614F4E7AF8F');
        $this->addSql('ALTER TABLE core_photo_gallery_invites DROP CONSTRAINT FK_CD5756464E7AF8F');
        $this->addSql('ALTER TABLE core_photo_gallery_item_comments DROP CONSTRAINT FK_B77E76C82A151376');
        $this->addSql('ALTER TABLE core_photo_gallery_items DROP CONSTRAINT FK_8B9DD5EA4E7AF8F');
        $this->addSql('ALTER TABLE core_photo_gallery_items DROP CONSTRAINT FK_8B9DD5EAEA9FDD75');
        $this->addSql('ALTER TABLE core_photo_gallery_picks DROP CONSTRAINT FK_16C9746F2A151376');
        $this->addSql('ALTER TABLE core_planning_events DROP CONSTRAINT FK_4E5AB77D3D865311');
        $this->addSql('ALTER TABLE core_planning_event_attendees DROP CONSTRAINT FK_F280AEA271F7E88B');
        $this->addSql('ALTER TABLE core_planning_event_attendees DROP CONSTRAINT FK_F280AEA2A76ED395');
        $this->addSql('ALTER TABLE core_plannings DROP CONSTRAINT FK_6431B97E3C61F9');
        $this->addSql('ALTER TABLE core_plannings DROP CONSTRAINT FK_6431B9CDEADB2A');
        $this->addSql('ALTER TABLE core_post_it_notes DROP CONSTRAINT FK_BF08188CA76ED395');
        $this->addSql('ALTER TABLE core_post_it_notes DROP CONSTRAINT FK_BF08188CCDEADB2A');
        $this->addSql('ALTER TABLE core_post_revisions DROP CONSTRAINT FK_D383B5FDF675F31B');
        $this->addSql('ALTER TABLE core_post_revisions DROP CONSTRAINT FK_D383B5FD4B89032C');
        $this->addSql('ALTER TABLE core_post_slug_history DROP CONSTRAINT FK_C04809954B89032C');
        $this->addSql('ALTER TABLE core_post_translations DROP CONSTRAINT FK_6A82AE686EFCB8B8');
        $this->addSql('ALTER TABLE core_post_translations DROP CONSTRAINT FK_6A82AE684B89032C');
        $this->addSql('ALTER TABLE core_post_type_fields DROP CONSTRAINT FK_D4BF279BF8A43BA0');
        $this->addSql('ALTER TABLE core_post_type_taxonomies DROP CONSTRAINT FK_8176B93CF8A43BA0');
        $this->addSql('ALTER TABLE core_post_type_taxonomies DROP CONSTRAINT FK_8176B93C9557E6F6');
        $this->addSql('ALTER TABLE core_posts DROP CONSTRAINT FK_DEDC9B1AF675F31B');
        $this->addSql('ALTER TABLE core_posts DROP CONSTRAINT FK_DEDC9B1AF8A43BA0');
        $this->addSql('ALTER TABLE core_posts DROP CONSTRAINT FK_DEDC9B1AE2532148');
        $this->addSql('ALTER TABLE core_post_terms DROP CONSTRAINT FK_23F5377F4B89032C');
        $this->addSql('ALTER TABLE core_post_terms DROP CONSTRAINT FK_23F5377F898CA496');
        $this->addSql('ALTER TABLE core_post_related_posts DROP CONSTRAINT FK_C8C5964C4B89032C');
        $this->addSql('ALTER TABLE core_post_related_posts DROP CONSTRAINT FK_C8C5964C7490C989');
        $this->addSql('ALTER TABLE core_project_columns DROP CONSTRAINT FK_472C0E8A166D1F9C');
        $this->addSql('ALTER TABLE core_project_labels DROP CONSTRAINT FK_592900D4166D1F9C');
        $this->addSql('ALTER TABLE core_project_saved_views DROP CONSTRAINT FK_BEC376367E3C61F9');
        $this->addSql('ALTER TABLE core_project_saved_views DROP CONSTRAINT FK_BEC37636166D1F9C');
        $this->addSql('ALTER TABLE core_project_sprints DROP CONSTRAINT FK_A506A74C166D1F9C');
        $this->addSql('ALTER TABLE core_project_task_comments DROP CONSTRAINT FK_285E885DF675F31B');
        $this->addSql('ALTER TABLE core_project_task_comments DROP CONSTRAINT FK_285E885D8DB60186');
        $this->addSql('ALTER TABLE core_project_task_items DROP CONSTRAINT FK_1174FDD18DB60186');
        $this->addSql('ALTER TABLE core_project_task_time_entries DROP CONSTRAINT FK_9CB4372DA76ED395');
        $this->addSql('ALTER TABLE core_project_task_time_entries DROP CONSTRAINT FK_9CB4372D8DB60186');
        $this->addSql('ALTER TABLE core_project_tasks DROP CONSTRAINT FK_CBA88656166D1F9C');
        $this->addSql('ALTER TABLE core_project_tasks DROP CONSTRAINT FK_CBA88656BE8E8ED5');
        $this->addSql('ALTER TABLE core_project_tasks DROP CONSTRAINT FK_CBA8865659EC7D60');
        $this->addSql('ALTER TABLE core_project_tasks DROP CONSTRAINT FK_CBA886568C24077B');
        $this->addSql('ALTER TABLE core_project_task_labels DROP CONSTRAINT FK_4C98B76A8DB60186');
        $this->addSql('ALTER TABLE core_project_task_labels DROP CONSTRAINT FK_4C98B76A33B92F39');
        $this->addSql('ALTER TABLE core_project_task_attachments DROP CONSTRAINT FK_595F791B8DB60186');
        $this->addSql('ALTER TABLE core_project_task_attachments DROP CONSTRAINT FK_595F791BEA9FDD75');
        $this->addSql('ALTER TABLE core_project_task_watchers DROP CONSTRAINT FK_B31CB1598DB60186');
        $this->addSql('ALTER TABLE core_project_task_watchers DROP CONSTRAINT FK_B31CB159A76ED395');
        $this->addSql('ALTER TABLE core_projects DROP CONSTRAINT FK_E351C507BDAD1998');
        $this->addSql('ALTER TABLE core_projects DROP CONSTRAINT FK_E351C507D2052C5E');
        $this->addSql('ALTER TABLE core_projects DROP CONSTRAINT FK_E351C5071A456F92');
        $this->addSql('ALTER TABLE core_project_crm_contacts DROP CONSTRAINT FK_85089333166D1F9C');
        $this->addSql('ALTER TABLE core_project_crm_contacts DROP CONSTRAINT FK_85089333E7A1254A');
        $this->addSql('ALTER TABLE core_reset_password_requests DROP CONSTRAINT FK_A837F08CA76ED395');
        $this->addSql('ALTER TABLE core_taxonomy_term_translations DROP CONSTRAINT FK_9E598575E2C35FC');
        $this->addSql('ALTER TABLE core_taxonomy_terms DROP CONSTRAINT FK_269912539557E6F6');
        $this->addSql('ALTER TABLE core_taxonomy_terms DROP CONSTRAINT FK_26991253727ACA70');
        $this->addSql('ALTER TABLE core_taxonomy_translations DROP CONSTRAINT FK_5EE2037B9557E6F6');
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT FK_42028409CDEADB2A');
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT FK_42028409ED5CA9E6');
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT FK_42028409783E3463');
        $this->addSql('ALTER TABLE core_vault_entries DROP CONSTRAINT FK_FB442AA4A76ED395');
        $this->addSql('ALTER TABLE core_vault_entries DROP CONSTRAINT FK_FB442AA4162CB942');
        $this->addSql('ALTER TABLE core_vault_folders DROP CONSTRAINT FK_288B4A6EA76ED395');
        $this->addSql('ALTER TABLE core_vault_folders DROP CONSTRAINT FK_288B4A6E727ACA70');
        $this->addSql('ALTER TABLE core_vault_user_configs DROP CONSTRAINT FK_6558F307A76ED395');
        $this->addSql('DROP TABLE app_sequence_counters');
        $this->addSql('DROP TABLE core_access_requests');
        $this->addSql('DROP TABLE core_agencies');
        $this->addSql('DROP TABLE core_assistant_conversations');
        $this->addSql('DROP TABLE core_assistant_messages');
        $this->addSql('DROP TABLE core_assistant_mount_points');
        $this->addSql('DROP TABLE core_audit_logs');
        $this->addSql('DROP TABLE core_billing_invoice_lines');
        $this->addSql('DROP TABLE core_billing_invoices');
        $this->addSql('DROP TABLE core_billing_ocr_jobs');
        $this->addSql('DROP TABLE core_billing_tiers');
        $this->addSql('DROP TABLE core_block_notes');
        $this->addSql('DROP TABLE core_comment_reactions');
        $this->addSql('DROP TABLE core_comments');
        $this->addSql('DROP TABLE core_crm_companies');
        $this->addSql('DROP TABLE core_crm_contact_tags');
        $this->addSql('DROP TABLE core_crm_contacts');
        $this->addSql('DROP TABLE core_crm_contact_tag_map');
        $this->addSql('DROP TABLE core_crm_deals');
        $this->addSql('DROP TABLE core_ecommerce_cart_items');
        $this->addSql('DROP TABLE core_ecommerce_carts');
        $this->addSql('DROP TABLE core_ecommerce_listing_categories');
        $this->addSql('DROP TABLE core_ecommerce_listing_category_translations');
        $this->addSql('DROP TABLE core_ecommerce_listing_tag_translations');
        $this->addSql('DROP TABLE core_ecommerce_listing_tags');
        $this->addSql('DROP TABLE core_ecommerce_listings');
        $this->addSql('DROP TABLE core_ecommerce_listing_category_map');
        $this->addSql('DROP TABLE core_ecommerce_listing_tag_map');
        $this->addSql('DROP TABLE core_ecommerce_order_lines');
        $this->addSql('DROP TABLE core_ecommerce_orders');
        $this->addSql('DROP TABLE core_employees');
        $this->addSql('DROP TABLE core_erp_products');
        $this->addSql('DROP TABLE core_form_field_translations');
        $this->addSql('DROP TABLE core_form_fields');
        $this->addSql('DROP TABLE core_form_submissions');
        $this->addSql('DROP TABLE core_form_translations');
        $this->addSql('DROP TABLE core_forms');
        $this->addSql('DROP TABLE core_ged_document_categories');
        $this->addSql('DROP TABLE core_ged_document_folders');
        $this->addSql('DROP TABLE core_ged_document_tags');
        $this->addSql('DROP TABLE core_ged_document_versions');
        $this->addSql('DROP TABLE core_ged_documents');
        $this->addSql('DROP TABLE core_ged_document_tag_map');
        $this->addSql('DROP TABLE core_locales');
        $this->addSql('DROP TABLE core_markdown_notes');
        $this->addSql('DROP TABLE core_media');
        $this->addSql('DROP TABLE core_media_folders');
        $this->addSql('DROP TABLE core_menu_item_translations');
        $this->addSql('DROP TABLE core_menu_items');
        $this->addSql('DROP TABLE core_menus');
        $this->addSql('DROP TABLE core_mount_points');
        $this->addSql('DROP TABLE core_notifications');
        $this->addSql('DROP TABLE core_personal_finance_budget');
        $this->addSql('DROP TABLE core_personal_finance_budget_item');
        $this->addSql('DROP TABLE core_personal_finance_budget_preset');
        $this->addSql('DROP TABLE core_personal_finance_budget_preset_item');
        $this->addSql('DROP TABLE core_personal_finance_categorization_rule');
        $this->addSql('DROP TABLE core_personal_finance_category');
        $this->addSql('DROP TABLE core_personal_finance_goal');
        $this->addSql('DROP TABLE core_personal_finance_recurring_transaction');
        $this->addSql('DROP TABLE core_personal_finance_scheduled_transaction');
        $this->addSql('DROP TABLE core_personal_finance_transaction');
        $this->addSql('DROP TABLE core_personal_finance_wallet');
        $this->addSql('DROP TABLE core_personal_finance_wallet_invitation');
        $this->addSql('DROP TABLE core_personal_finance_wallet_member');
        $this->addSql('DROP TABLE core_photo_galleries');
        $this->addSql('DROP TABLE core_photo_gallery_finalizations');
        $this->addSql('DROP TABLE core_photo_gallery_invites');
        $this->addSql('DROP TABLE core_photo_gallery_item_comments');
        $this->addSql('DROP TABLE core_photo_gallery_items');
        $this->addSql('DROP TABLE core_photo_gallery_picks');
        $this->addSql('DROP TABLE core_planning_events');
        $this->addSql('DROP TABLE core_planning_event_attendees');
        $this->addSql('DROP TABLE core_plannings');
        $this->addSql('DROP TABLE core_post_it_notes');
        $this->addSql('DROP TABLE core_post_revisions');
        $this->addSql('DROP TABLE core_post_slug_history');
        $this->addSql('DROP TABLE core_post_translations');
        $this->addSql('DROP TABLE core_post_type_fields');
        $this->addSql('DROP TABLE core_post_types');
        $this->addSql('DROP TABLE core_post_type_taxonomies');
        $this->addSql('DROP TABLE core_posts');
        $this->addSql('DROP TABLE core_post_terms');
        $this->addSql('DROP TABLE core_post_related_posts');
        $this->addSql('DROP TABLE core_project_columns');
        $this->addSql('DROP TABLE core_project_labels');
        $this->addSql('DROP TABLE core_project_saved_views');
        $this->addSql('DROP TABLE core_project_sprints');
        $this->addSql('DROP TABLE core_project_task_comments');
        $this->addSql('DROP TABLE core_project_task_items');
        $this->addSql('DROP TABLE core_project_task_time_entries');
        $this->addSql('DROP TABLE core_project_tasks');
        $this->addSql('DROP TABLE core_project_task_labels');
        $this->addSql('DROP TABLE core_project_task_attachments');
        $this->addSql('DROP TABLE core_project_task_watchers');
        $this->addSql('DROP TABLE core_projects');
        $this->addSql('DROP TABLE core_project_crm_contacts');
        $this->addSql('DROP TABLE core_reset_password_requests');
        $this->addSql('DROP TABLE core_services');
        $this->addSql('DROP TABLE core_settings');
        $this->addSql('DROP TABLE core_taxonomies');
        $this->addSql('DROP TABLE core_taxonomy_term_translations');
        $this->addSql('DROP TABLE core_taxonomy_terms');
        $this->addSql('DROP TABLE core_taxonomy_translations');
        $this->addSql('DROP TABLE core_themes');
        $this->addSql('DROP TABLE core_users');
        $this->addSql('DROP TABLE core_vault_entries');
        $this->addSql('DROP TABLE core_vault_folders');
        $this->addSql('DROP TABLE core_vault_user_configs');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
