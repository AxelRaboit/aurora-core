<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function sprintf;

/**
 * Drops the schema of the thirteen modules that left this repository.
 *
 * `Version20260524091527` creates the tables of Crm, Ecommerce, Billing,
 * Photo, Project, Erp, Hr, Vault, Assistant, PersonalFinance, Tools, PdfForm
 * and the old Notes in its `up()`, and drops them only in its `down()`. The
 * modules were extracted between July and August 2026; no migration since has
 * touched those tables. So a database built from this suite *before* the
 * extractions still carries 65 tables, 58 sequences and two columns on
 * `core_users` that no entity has mapped for months.
 *
 * **A database built after them carries none**, which is worth saying because
 * this docblock used to claim the opposite, "a client's production included",
 * on the strength of having counted one machine. Measured on app.axelraboit.fr
 * on 2026-09-16: none of the 65 tables, none of the 58 sequences, and neither
 * column - its first applied migration is dated August 2026, after the last
 * extraction, so the dead schema was never created there. This migration is a
 * no-op on that install, which is the correct outcome and not a reason to
 * narrow it: what it exists for is the databases that predate the split.
 *
 * The visible cost is `doctrine:migrations:diff`: it compares the mapping to
 * the schema, correctly finds 65 tables nothing maps, and proposes to drop
 * them all. Every migration in this repository has therefore been written by
 * hand since the first extraction, with the generated diff used only as a
 * source of statements to copy out. This migration is what makes the tool
 * usable again.
 *
 * **`preUp()` refuses to run if any of those tables holds a row.** The check
 * is not ceremony. The two databases counted so far hold nothing - locally
 * every one is empty, and on the production above they do not exist at all -
 * but a client who used a module before it was extracted would have rows
 * here, and a `DROP TABLE` would take them with no way back. A deploy that stops with a list of table names is a problem
 * somebody can solve. A deploy that succeeds and deletes their invoices is
 * not.
 *
 * The two columns on `core_users` go first, with their constraints. They are
 * the only links between the living schema and the dead one, and dropping
 * them is the same gesture `Version20260823140000` already made for
 * `core_plannings.agency_id`, for the same reason. `manager_id` stays: it is
 * mapped, it points at `core_users` itself, and it has nothing to do with
 * this.
 *
 * `CASCADE` on the table drops is what lets them be listed alphabetically
 * rather than in dependency order. Once `core_users` has let go, nothing
 * outside the list references anything inside it, so the only constraints
 * `CASCADE` can reach are the ones between the tables being dropped anyway.
 *
 * There is no `down()`. Recreating 65 tables would mean copying three
 * thousand lines out of the initial migration to rebuild empty structures
 * whose code is gone, and it would not bring back a single row for the one
 * case where that would matter.
 */
final class Version20260916120000 extends AbstractMigration
{
    /**
     * @var list<string>
     */
    private const array ORPHAN_TABLES = [
        'core_agencies',
        'core_assistant_conversations',
        'core_assistant_messages',
        'core_assistant_mount_points',
        'core_billing_invoice_lines',
        'core_billing_invoices',
        'core_billing_ocr_jobs',
        'core_billing_tiers',
        'core_block_notes',
        'core_crm_companies',
        'core_crm_contact_tag_map',
        'core_crm_contact_tags',
        'core_crm_contacts',
        'core_crm_deals',
        'core_ecommerce_cart_items',
        'core_ecommerce_carts',
        'core_ecommerce_listing_categories',
        'core_ecommerce_listing_category_map',
        'core_ecommerce_listing_category_translations',
        'core_ecommerce_listing_tag_map',
        'core_ecommerce_listing_tag_translations',
        'core_ecommerce_listing_tags',
        'core_ecommerce_listings',
        'core_ecommerce_order_lines',
        'core_ecommerce_orders',
        'core_employees',
        'core_erp_products',
        'core_markdown_notes',
        'core_personal_finance_budget',
        'core_personal_finance_budget_item',
        'core_personal_finance_budget_preset',
        'core_personal_finance_budget_preset_item',
        'core_personal_finance_categorization_rule',
        'core_personal_finance_category',
        'core_personal_finance_goal',
        'core_personal_finance_recurring_transaction',
        'core_personal_finance_scheduled_transaction',
        'core_personal_finance_transaction',
        'core_personal_finance_wallet',
        'core_personal_finance_wallet_invitation',
        'core_personal_finance_wallet_member',
        'core_photo_galleries',
        'core_photo_gallery_finalizations',
        'core_photo_gallery_invites',
        'core_photo_gallery_item_comments',
        'core_photo_gallery_items',
        'core_photo_gallery_picks',
        'core_post_it_notes',
        'core_project_columns',
        'core_project_crm_contacts',
        'core_project_labels',
        'core_project_saved_views',
        'core_project_sprints',
        'core_project_task_comments',
        'core_project_task_documents',
        'core_project_task_items',
        'core_project_task_labels',
        'core_project_task_time_entries',
        'core_project_task_watchers',
        'core_project_tasks',
        'core_projects',
        'core_services',
        'core_vault_entries',
        'core_vault_folders',
        'core_vault_user_configs',
    ];

    /**
     * @var list<string>
     */
    private const array ORPHAN_SEQUENCES = [
        'seq_core_agency_id',
        'seq_core_assistant_conversation_id',
        'seq_core_assistant_message_id',
        'seq_core_assistant_mount_point_id',
        'seq_core_block_note_id',
        'seq_core_cart_id',
        'seq_core_cart_item_id',
        'seq_core_company_id',
        'seq_core_contact_id',
        'seq_core_contact_tag_id',
        'seq_core_core_project_column_id',
        'seq_core_core_project_id',
        'seq_core_core_project_label_id',
        'seq_core_core_project_saved_view_id',
        'seq_core_core_project_sprint_id',
        'seq_core_core_project_task_comment_id',
        'seq_core_core_project_task_id',
        'seq_core_core_project_task_item_id',
        'seq_core_core_project_task_time_entry_id',
        'seq_core_deal_id',
        'seq_core_employee_id',
        'seq_core_gallery_finalization_id',
        'seq_core_gallery_id',
        'seq_core_gallery_invite_id',
        'seq_core_gallery_item_comment_id',
        'seq_core_gallery_item_id',
        'seq_core_gallery_pick_id',
        'seq_core_invoice_id',
        'seq_core_invoice_line_id',
        'seq_core_listing_category_id',
        'seq_core_listing_category_translation_id',
        'seq_core_listing_id',
        'seq_core_listing_tag_id',
        'seq_core_listing_tag_translation_id',
        'seq_core_markdown_note_id',
        'seq_core_ocr_job_id',
        'seq_core_order_id',
        'seq_core_order_line_id',
        'seq_core_personal_finance_budget_id',
        'seq_core_personal_finance_budget_item_id',
        'seq_core_personal_finance_budget_preset_id',
        'seq_core_personal_finance_budget_preset_item_id',
        'seq_core_personal_finance_categorization_rule_id',
        'seq_core_personal_finance_category_id',
        'seq_core_personal_finance_goal_id',
        'seq_core_personal_finance_recurring_transaction_id',
        'seq_core_personal_finance_scheduled_transaction_id',
        'seq_core_personal_finance_transaction_id',
        'seq_core_personal_finance_wallet_id',
        'seq_core_personal_finance_wallet_invitation_id',
        'seq_core_personal_finance_wallet_member_id',
        'seq_core_post_it_note_id',
        'seq_core_product_id',
        'seq_core_service_id',
        'seq_core_tiers_id',
        'seq_core_vault_entry_id',
        'seq_core_vault_folder_id',
        'seq_core_vault_user_config_id',
    ];

    public function getDescription(): string
    {
        return 'Drops the 65 tables, 58 sequences and 2 user columns left by the extracted modules';
    }

    /**
     * Refuses the migration when any orphan table still holds data.
     *
     * Reads the tables that actually exist rather than the whole list: an
     * install that already lost some of them (a hand-cleaned database, a
     * client that never had the module) must not fail on a missing table.
     */
    public function preUp(Schema $schema): void
    {
        $populated = [];

        foreach (self::ORPHAN_TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $count = (int) $this->connection->fetchOne(sprintf('SELECT count(*) FROM %s', $table));

            if ($count > 0) {
                $populated[] = sprintf('%s (%d)', $table, $count);
            }
        }

        $this->abortIf(
            [] !== $populated,
            'Refusing to drop tables that still hold data: '.implode(', ', $populated)
            .'. These belong to modules extracted from aurora-core. Export or archive them, '
            .'empty the tables, then run the migration again.'
        );
    }

    public function up(Schema $schema): void
    {
        // The only two links from the living schema into the dead one.
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT IF EXISTS fk_42028409cdeadb2a');
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT IF EXISTS fk_42028409ed5ca9e6');
        $this->addSql('ALTER TABLE core_users DROP COLUMN IF EXISTS agency_id');
        $this->addSql('ALTER TABLE core_users DROP COLUMN IF EXISTS service_id');

        foreach (self::ORPHAN_TABLES as $table) {
            $this->addSql(sprintf('DROP TABLE IF EXISTS %s CASCADE', $table));
        }

        foreach (self::ORPHAN_SEQUENCES as $sequence) {
            $this->addSql(sprintf('DROP SEQUENCE IF EXISTS %s', $sequence));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'The extracted modules\' schema cannot be restored: the entities, managers and '
            .'controllers that gave those tables meaning left the repository months before '
            .'this migration dropped them.'
        );
    }
}
