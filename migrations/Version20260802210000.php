<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Form field conditions stop being nullable, and submissions get the index
 * the screen that reads them needs.
 *
 * `conditions` and `conditions_logic` were both nullable, so "this field is
 * always shown" had two spellings — `NULL` and `[]` — and every reader had to
 * handle both. One of them is enough, and the empty list is the one that says
 * what it means. `conditions_logic` gains a default for the same reason: a
 * row with no logic was neither "all" nor "any".
 *
 * Existing rows are filled in before the columns are tightened, so the
 * migration is safe on a database that already holds forms.
 */
final class Version20260802210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make form field conditions non-nullable; index form submissions by form and date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE core_form_fields SET conditions = '[]'::json WHERE conditions IS NULL");
        $this->addSql("UPDATE core_form_fields SET conditions_logic = 'and' WHERE conditions_logic IS NULL");

        $this->addSql('ALTER TABLE core_form_fields ALTER conditions SET NOT NULL');
        $this->addSql("ALTER TABLE core_form_fields ALTER conditions_logic SET DEFAULT 'and'");
        $this->addSql('ALTER TABLE core_form_fields ALTER conditions_logic SET NOT NULL');

        $this->addSql('CREATE INDEX idx_form_submission_form_date ON core_form_submissions (form_id, submitted_at)');

        $this->addSql('ALTER INDEX uniq_abf898d95ff69b7d4180c698 RENAME TO uniq_form_translation_locale');
        $this->addSql('ALTER INDEX uniq_abf898d94180c698989d9b62 RENAME TO uniq_form_locale_slug');
        $this->addSql('ALTER INDEX uniq_490a58f5443707b04180c698 RENAME TO uniq_form_field_translation_locale');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX uniq_form_field_translation_locale RENAME TO uniq_490a58f5443707b04180c698');
        $this->addSql('ALTER INDEX uniq_form_locale_slug RENAME TO uniq_abf898d94180c698989d9b62');
        $this->addSql('ALTER INDEX uniq_form_translation_locale RENAME TO uniq_abf898d95ff69b7d4180c698');

        $this->addSql('DROP INDEX idx_form_submission_form_date');

        $this->addSql('ALTER TABLE core_form_fields ALTER conditions_logic DROP NOT NULL');
        $this->addSql('ALTER TABLE core_form_fields ALTER conditions_logic DROP DEFAULT');
        $this->addSql('ALTER TABLE core_form_fields ALTER conditions DROP NOT NULL');
    }
}
