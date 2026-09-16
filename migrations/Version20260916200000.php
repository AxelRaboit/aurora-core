<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes `allowed_upload_extensions`, a control that never controlled anything.
 *
 * It was declared in `ApplicationParameterEnum`, labelled, described, given a
 * default and a placeholder, shown in the media group of the settings screen,
 * edited and stored - and read by no line of code in the repository. An
 * administrator removing `svg` from the list changed nothing at all, which is
 * worse than having no control: the neighbouring `file_versions_limit` does
 * work, so the group looks trustworthy.
 *
 * Removed rather than wired, unlike `max_upload_size_mb` next to it, because
 * the library declines to keep a type allow-list on purpose. `UploadPolicy`
 * argues it: a list is a promise to have thought of every dangerous format,
 * and a library that refuses types is one people work around by renaming
 * files. What makes accepting them safe is at the other end - `BinaryFileServer`
 * sends `nosniff` and hands over a download for the handful of types a browser
 * would otherwise run. Making the setting real would have imposed a
 * restriction nobody asked for, on installs whose stored list predates the
 * decision.
 *
 * The guest allow-list is a different thing and stays in code. It exists
 * because whoever holds a space link holds a secret address rather than an
 * account, and `svg` is absent from it by name. An editable version of that
 * list would be a way to reopen, from a form, a hole that was closed in
 * 0.9.188.
 *
 * `down()` restores the row with the default it shipped with, which is what a
 * reinstall of that version would have produced. It cannot restore a list
 * somebody had tailored, and tailoring it had no effect anyway.
 */
final class Version20260916200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Removes the allowed_upload_extensions setting, which nothing read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM core_settings WHERE setting_key = 'allowed_upload_extensions'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO core_settings (setting_key, value, setting_type, setting_group, description)
            VALUES (
                'allowed_upload_extensions',
                'jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,zip',
                'string',
                'media',
                'backend.parameters.allowed_upload_extensions.description'
            )
            SQL);
    }
}
