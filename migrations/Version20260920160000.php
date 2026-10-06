<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Aurora\Module\Studio\SpaceAccess\Service\SpaceAccessLinkLabel;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A guest is no longer signed by their address.
 *
 * **What was written stays readable by the other guests.** The author name is
 * frozen in the row when it is written - on purpose, a message must not change
 * signer because a link was renamed - so fixing the code only fixes the
 * future. Threads already held would keep showing addresses, and that is
 * exactly what this stops.
 *
 * Guest rows therefore take the label of their link. For a link issued before
 * the label was required, the name is derived from the left part of the
 * address, dots and hyphens turned into spaces - the same rule as
 * {@see SpaceAccessLinkLabel}, written
 * a second time because a migration must call nothing that could
 * disappear.
 *
 * Rolling back does not restore the addresses: they are no longer anywhere in
 * these rows, and putting them back would redo the leak in reverse.
 */
final class Version20260920160000 extends AbstractMigration
{
    private const array TABLES = [
        'core_studio_space_chat_messages',
        'core_studio_space_content_comments',
        'core_studio_space_content_attachments',
    ];

    public function getDescription(): string
    {
        return 'Guest-authored rows carry their link label instead of an email address';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(<<<SQL
                UPDATE {$table} AS t
                SET author_label = CASE
                    WHEN coalesce(btrim(l.label), '') <> '' THEN btrim(l.label)
                    ELSE initcap(btrim(translate(split_part(l.recipient_email, '@', 1), '._-+', '    ')))
                END
                FROM core_studio_space_access_links AS l
                WHERE t.author_link_id = l.id
                  AND t.author_label LIKE '%@%'
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            "Les adresses ne sont plus dans ces lignes : les y remettre referait la fuite à l'envers.",
        );
    }
}
