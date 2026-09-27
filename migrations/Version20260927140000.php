<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A role for the steps of a space's board: which shared stage a column
 * stands for, so the counts across spaces can be computed.
 *
 * The steps a space was born with get theirs back from their name, in the
 * languages the defaults were written in. A step somebody named themselves
 * keeps no role until somebody gives it one: guessing would make the counts
 * lie, and an empty role only leaves a column out of them.
 */
final class Version20260927140000 extends AbstractMigration
{
    private const array ROLES_BY_NAME = [
        'idea' => ['Idées', 'Ideas'],
        'production' => ['En rédaction', 'Writing'],
        'review' => ['À valider', 'To approve'],
        'scheduled' => ['Programmé', 'Scheduled'],
        'published' => ['Publié', 'Published'],
    ];

    public function getDescription(): string
    {
        return 'Studio: an optional role on the steps of a space board';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns ADD role VARCHAR(20) DEFAULT NULL');

        foreach (self::ROLES_BY_NAME as $role => $names) {
            $this->addSql(
                'UPDATE core_studio_space_content_columns SET role = ? WHERE role IS NULL AND name IN (?, ?)',
                [$role, ...$names],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns DROP role');
    }
}
