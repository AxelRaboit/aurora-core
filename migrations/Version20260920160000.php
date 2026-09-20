<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Aurora\Module\Studio\SpaceAccess\Service\SpaceAccessLinkLabel;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un invité n'est plus signé par son adresse.
 *
 * **Ce qui était écrit reste lisible par les autres invités.** Le nom d'auteur
 * est figé dans la ligne au moment où elle est écrite - c'est voulu, un message
 * ne doit pas changer de signataire parce qu'un lien a été renommé - donc
 * corriger le code ne corrige que l'avenir. Les fils déjà tenus continueraient
 * d'afficher des adresses, et c'est justement ce qu'on arrête.
 *
 * Les lignes d'invités reprennent donc le libellé de leur lien. Pour un lien
 * émis avant que le libellé soit obligatoire, le nom se dérive de la partie
 * gauche de l'adresse, points et tirets rendus aux espaces - la même règle que
 * {@see SpaceAccessLinkLabel}, écrite
 * une seconde fois parce qu'une migration ne doit rien appeler qui puisse
 * disparaître.
 *
 * Le retour en arrière ne rend pas les adresses : elles ne sont plus nulle part
 * dans ces lignes, et les remettre serait refaire la fuite à l'envers.
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
