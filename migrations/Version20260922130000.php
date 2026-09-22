<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La date avant laquelle un client doit avoir répondu.
 *
 * **Une colonne de plus, et pas un usage détourné de `scheduled_at`.** Une
 * publication prévue le 30 ne se valide pas le 30 : il faut le temps de
 * produire et parfois de reprendre. Les deux dates répondent à deux questions
 * différentes, et les confondre fait découvrir la veille qu'il manquait un
 * avis.
 *
 * Nullable, parce que la plupart des cartes n'en portent pas : une colonne
 * obligatoire aurait forcé à inventer une échéance à chaque création. Rien ne
 * s'y accroche côté application - pas de blocage, pas de déprogrammation - donc
 * une valeur dépassée ne fait rien d'autre qu'apparaître.
 *
 * Écrite à la main plutôt que par `doctrine:migrations:diff` : la génération
 * automatique ramassait une dérive d'index préexistante et aurait supprimé une
 * dizaine d'index qui n'ont rien à voir avec ce changement.
 */
final class Version20260922130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A review deadline on a space content item, distinct from its publication date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD review_by TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP review_by');
    }
}
