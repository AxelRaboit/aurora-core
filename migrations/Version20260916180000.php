<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the two settings rows of a module that no longer exists.
 *
 * `ModuleParameterEnum` kept a `MediaBackend` and a `MediaLibrary` case after
 * the Media module was extracted in July 2026, and `CoreModuleParameterProvider`
 * yields every case to `aurora:application-parameter` - so every installation
 * since has created `modules_media_backend` and `modules_media_library` in
 * `core_settings`, and kept them, for a module nobody could reach.
 *
 * One of the two was worse than dead. `MediaLibrary` pointed its label at
 * `backend.nav.media`, a key that does not exist: the real one is
 * `backend.nav.sections.media`. Anything rendering that toggle showed a raw
 * translation key.
 *
 * Deleting rows rather than a schema change, so the values go with the cases
 * that named them. Nothing reads these two: the module they belonged to is not
 * in `src/Module/`, and no `ModuleToggleProviderInterface` declares them.
 *
 * `down()` puts them back with the default every module toggle has, which is
 * the honest reverse - the value a reinstall would have produced. It cannot
 * restore what somebody had switched off, and there was nothing to switch off.
 */
final class Version20260916180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Removes the settings rows of the extracted Media module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM core_settings WHERE setting_key IN ('modules_media_backend', 'modules_media_library')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO core_settings (setting_key, value, setting_type, setting_group, description)
            VALUES
                ('modules_media_backend', '1', 'bool', 'modules', 'backend.modules.media_backend_description'),
                ('modules_media_library', '1', 'bool', 'modules', 'backend.nav.media_description')
            SQL);
    }
}
