<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

use function mb_strlen;
use function sprintf;

/**
 * Les présentations de Studio deviennent des livrables au format diaporama.
 *
 * Les deux étapes précédentes ont préparé la place (le format, le modèle, le
 * client, puis les diapositives d'un livrable) ; celle-ci déménage les
 * données et retire les tables des présentations. Écrite à la main, en SQL :
 * une migration ne doit rien devoir aux classes qu'elle rend inutiles.
 *
 * Ce qui part où :
 *
 * 1. **Les présentations** deviennent des livrables de Studio : sans espace,
 *    sans auteur (une présentation n'en avait pas), partagés avec l'équipe,
 *    au format `slides`. La description devient le résumé, le thème et ses
 *    retouches deviennent ceux des diapositives ; titre, modèle, client,
 *    dates et corbeille sont gardés tels quels. La langue est celle du
 *    réglage `default_locale`, ou `fr` s'il n'y en a pas. Les identifiants
 *    viennent de la séquence des livrables, et une table de passage
 *    (`core_studio_deck_merge`) garde la correspondance le temps de la
 *    migration.
 * 2. **Les catégories** rejoignent celles des livrables par leur nom, sans
 *    tenir compte de la casse ni des espaces autour : « Lancement » retrouve
 *    « lancement ». Un nom inconnu crée une catégorie de livrables, rangée
 *    après les autres dans l'ordre où il était.
 * 3. **Les diapositives restent dans leur table**, `core_deck_slides`, avec
 *    leurs identifiants et leur séquence : `deliverable_id` est rempli depuis
 *    la table de passage, puis `deck_id` disparaît et `deliverable_id` devient
 *    obligatoire. C'est le choix le plus simple qui garde les identifiants :
 *    une table neuve aurait demandé une copie et un recalage de séquence pour
 *    le même résultat. Le nom de la table suivra le déménagement des classes
 *    du moteur de diapositives.
 * 4. **Les liens de partage** deviennent des liens de lecture, colonne pour
 *    colonne : le jeton chiffré est recopié octet pour octet (le chiffrement
 *    ne dépend pas de la table), avec son empreinte, son libellé, ses dates,
 *    son compteur et son mot de passe. Une adresse déjà envoyée garde son
 *    jeton ; `/decks/{jeton}` la renvoie vers `/deliverables/{jeton}`.
 * 5. **Les zones « deck » des pages du site** (et de leurs révisions, et des
 *    grilles de livrables qui portent la même clé) gardent leur type mais
 *    nomment désormais un livrable : `deckId` devient `deliverableId`, avec
 *    l'identifiant du livrable qui a pris la place de la présentation. Une
 *    zone qui nommait une présentation disparue ne nomme plus rien.
 * 6. **Les droits** `studio.decks.*` deviennent `studio.deliverables.*`, sans
 *    doublon ; `studio.deck_categories.manage` disparaît (les catégories des
 *    livrables suivent le droit de les modifier).
 * 7. **L'interrupteur des présentations** disparaît, du réglage général comme
 *    des modules coupés de chaque personne. Qui avait les présentations sans
 *    les livrables reçoit les livrables : sans cela, ses présentations
 *    disparaîtraient avec le module qui les portait.
 * 8. **Le menu** : `suite_studio_decks` sort des entrées masquées, de l'ordre
 *    et des alias du menu (même piège que `Version20260912210000` : les clés
 *    des réglages ne changent pas, les noms de route sont dans leur valeur).
 * 9. Les tables et séquences des présentations sont supprimées, ainsi que les
 *    tables de passage et un éventuel message de purge des présentations
 *    encore en file.
 *
 * Irréversible : les présentations n'existent plus pour y revenir.
 */
final class Version20261006140000 extends AbstractMigration
{
    /** Les droits qui changent de nom, sans le point final. */
    private const string OLD_PRIVILEGE = 'studio.decks.';

    private const string NEW_PRIVILEGE = 'studio.deliverables.';

    public function getDescription(): string
    {
        return 'Studio: presentations become slides deliverables (data, categories, share links, editorial zones, privileges, toggle, menu), deck tables dropped';
    }

    public function up(Schema $schema): void
    {
        $this->deliverables();
        $this->slides();
        $this->links();
        $this->grids();
        $this->privileges();
        $this->toggle();
        $this->menu();
        $this->dropDecks();
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Presentations have become deliverables: there is no deck left to go back to.');
    }

    /** Étapes 1 et 2 : les catégories d'abord, puis les présentations. */
    private function deliverables(): void
    {
        $this->addSql('CREATE TABLE core_studio_deck_merge (deck_id INT NOT NULL, deliverable_id INT NOT NULL, PRIMARY KEY (deck_id))');
        $this->addSql("INSERT INTO core_studio_deck_merge (deck_id, deliverable_id) SELECT id, nextval('seq_core_deliverable_id') FROM core_decks ORDER BY id");

        $this->addSql('CREATE TABLE core_studio_deck_category_merge (deck_category_id INT NOT NULL, deliverable_category_id INT DEFAULT NULL, PRIMARY KEY (deck_category_id))');
        $this->addSql(<<<'SQL'
            INSERT INTO core_studio_deck_category_merge (deck_category_id, deliverable_category_id)
            SELECT deck_category.id,
                   (SELECT MIN(category.id) FROM core_studio_deliverable_categories category
                     WHERE LOWER(TRIM(category.name)) = LOWER(TRIM(deck_category.name)))
              FROM core_deck_categories deck_category
            SQL);
        // Un seul nouveau nom par nom inconnu, même si deux catégories de
        // présentations ne différaient que par la casse : la première, dans
        // l'ordre où elles étaient rangées, donne le nom et la couleur.
        $this->addSql(<<<'SQL'
            INSERT INTO core_studio_deliverable_categories (id, name, color, position, created_at, updated_at)
            SELECT nextval('seq_core_deliverable_category_id'),
                   missing.name,
                   missing.color,
                   (SELECT COALESCE(MAX(position), -1) FROM core_studio_deliverable_categories) + ROW_NUMBER() OVER (ORDER BY missing.position, missing.id),
                   missing.created_at,
                   missing.updated_at
              FROM (SELECT DISTINCT ON (LOWER(TRIM(deck_category.name))) deck_category.*
                      FROM core_deck_categories deck_category
                      JOIN core_studio_deck_category_merge category_map ON category_map.deck_category_id = deck_category.id
                     WHERE category_map.deliverable_category_id IS NULL
                     ORDER BY LOWER(TRIM(deck_category.name)), deck_category.position, deck_category.id) missing
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE core_studio_deck_category_merge category_map
               SET deliverable_category_id = (
                   SELECT MIN(category.id)
                     FROM core_studio_deliverable_categories category
                     JOIN core_deck_categories deck_category ON LOWER(TRIM(category.name)) = LOWER(TRIM(deck_category.name))
                    WHERE deck_category.id = category_map.deck_category_id)
             WHERE category_map.deliverable_category_id IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO core_studio_deliverables (
                id, space_id, owner_id, scope, title, summary, locale,
                grid_layout, grid_content, appearance, reading_header, visible_to_client,
                category_id, thumbnail_id, deleted_at, format, template, customer_id,
                slide_theme, slide_style, created_at, updated_at
            )
            SELECT deck_map.deliverable_id, NULL, NULL, 'shared', deck.title, NULLIF(TRIM(deck.description), ''),
                   COALESCE((SELECT NULLIF(TRIM(setting."value"), '') FROM core_settings setting WHERE setting.setting_key = 'default_locale' LIMIT 1), 'fr'),
                   '{}', '{}', '{}', '{}', false,
                   category_map.deliverable_category_id, NULL, deck.deleted_at, 'slides', deck.template, deck.customer_id,
                   deck.theme, deck.style, deck.created_at, deck.updated_at
              FROM core_decks deck
              JOIN core_studio_deck_merge deck_map ON deck_map.deck_id = deck.id
              LEFT JOIN core_studio_deck_category_merge category_map ON category_map.deck_category_id = deck.category_id
            SQL);
    }

    /** Étape 3 : les diapositives changent de propriétaire, pas de table. */
    private function slides(): void
    {
        $this->addSql('UPDATE core_deck_slides slide SET deliverable_id = deck_map.deliverable_id FROM core_studio_deck_merge deck_map WHERE slide.deck_id = deck_map.deck_id');
        // Aucune ne devrait rester sans propriétaire : `deck_id` était
        // obligatoire jusqu'à l'étape précédente, et une diapositive de
        // livrable a déjà le sien. Dit quand même, avant la contrainte.
        $this->addSql('DELETE FROM core_deck_slides WHERE deliverable_id IS NULL');
        $this->addSql('ALTER TABLE core_deck_slides DROP CONSTRAINT IF EXISTS FK_5F851CE8111948DC');
        $this->addSql('DROP INDEX IF EXISTS IDX_5F851CE8111948DC');
        $this->addSql('ALTER TABLE core_deck_slides DROP deck_id');
        $this->addSql('ALTER TABLE core_deck_slides ALTER deliverable_id SET NOT NULL');
    }

    /** Étape 4 : les liens de partage, colonne pour colonne, jeton chiffré compris. */
    private function links(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO core_studio_deliverable_links (
                id, deliverable_id, token, token_hash, label, expires_at, revoked_at,
                last_used_at, created_at, open_count, password_hash, hidden_at
            )
            SELECT nextval('seq_core_deliverable_link_id'), deck_map.deliverable_id, link.token, link.token_hash, link.label,
                   link.expires_at, link.revoked_at, link.last_used_at, link.created_at, link.open_count,
                   link.password_hash, link.hidden_at
              FROM core_deck_share_links link
              JOIN core_studio_deck_merge deck_map ON deck_map.deck_id = link.deck_id
             ORDER BY link.id
            SQL);
    }

    /**
     * Étape 5 : `deckId` devient `deliverableId` dans les grilles.
     *
     * Le texte du JSON plutôt que ses fonctions : une zone peut être imbriquée
     * dans une autre (une pile porte des enfants), et la clé est la même à
     * toutes les profondeurs. D'abord les identifiants connus, un par un ;
     * puis ceux qui ne nomment plus rien sont vidés, pour ne jamais pointer
     * par hasard vers le livrable qui porte le même numéro ; enfin la clé des
     * zones qui n'en avaient pas.
     */
    private function grids(): void
    {
        $columns = [
            ['core_posts', 'grid_layout'],
            ['core_post_revisions', 'snapshot'],
            ['core_studio_deliverables', 'grid_layout'],
        ];

        $updates = '';
        foreach ($columns as [$table, $column]) {
            $updates .= sprintf(
                <<<'SQL'
                            UPDATE %1$s
                               SET %2$s = regexp_replace(%2$s::text, '"deckId":(\s*)' || mapping.deck_id || '(?![0-9])', '"deliverableId":\1' || mapping.deliverable_id, 'g')::json
                             WHERE %2$s::text ~ ('"deckId":\s*' || mapping.deck_id || '(?![0-9])');

                    SQL,
                $table,
                $column,
            );
        }

        $this->addSql(sprintf(
            <<<'SQL'
                DO $$
                DECLARE mapping RECORD;
                BEGIN
                    FOR mapping IN SELECT deck_id, deliverable_id FROM core_studio_deck_merge LOOP
                %s    END LOOP;
                END $$
                SQL,
            $updates,
        ));

        foreach ($columns as [$table, $column]) {
            $this->addSql(sprintf(
                "UPDATE %1\$s SET %2\$s = regexp_replace(%2\$s::text, '\"deckId\":(\\s*)[0-9]+', '\"deliverableId\":\\1null', 'g')::json WHERE %2\$s::text ~ '\"deckId\":\\s*[0-9]'",
                $table,
                $column,
            ));
            $this->addSql(sprintf(
                "UPDATE %1\$s SET %2\$s = REPLACE(%2\$s::text, '\"deckId\":', '\"deliverableId\":')::json WHERE %2\$s::text LIKE '%%\"deckId\":%%'",
                $table,
                $column,
            ));
        }
    }

    /**
     * Étape 6 : les droits, renommés sans doublon et dans l'ordre où la
     * personne les avait reçus.
     */
    private function privileges(): void
    {
        $this->addSql(sprintf(
            <<<'SQL'
                UPDATE core_users
                   SET privileges = (
                       SELECT COALESCE(json_agg(renamed.privilege ORDER BY renamed.rank), '[]'::json)
                         FROM (SELECT CASE WHEN granted.value LIKE '%1$s%%'
                                           THEN '%2$s' || SUBSTRING(granted.value FROM %3$d)
                                           ELSE granted.value END AS privilege,
                                      MIN(granted.rank) AS rank
                                 FROM json_array_elements_text(core_users.privileges) WITH ORDINALITY AS granted(value, rank)
                                WHERE granted.value <> 'studio.deck_categories.manage'
                                GROUP BY 1) renamed)
                 WHERE privileges::text LIKE '%%studio.deck%%'
                SQL,
            self::OLD_PRIVILEGE,
            self::NEW_PRIVILEGE,
            mb_strlen(self::OLD_PRIVILEGE) + 1,
        ));
    }

    /** Étape 7 : l'interrupteur des présentations, général et par personne. */
    private function toggle(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE core_settings SET "value" = '1'
             WHERE setting_key = 'modules_studio_deliverables' AND "value" = '0'
               AND EXISTS (SELECT 1 FROM core_settings decks WHERE decks.setting_key = 'modules_studio_decks' AND decks."value" = '1')
            SQL);
        $this->addSql("DELETE FROM core_settings WHERE setting_key = 'modules_studio_decks'");

        // Coupé pour la personne : `disabled_modules` liste ce qu'elle n'a
        // pas. Les présentations allumées et les livrables coupés, elle
        // reçoit les livrables.
        $this->addSql(<<<'SQL'
            UPDATE core_users
               SET disabled_modules = (
                   SELECT COALESCE(json_agg(module.value ORDER BY module.rank), '[]'::json)
                     FROM json_array_elements_text(core_users.disabled_modules) WITH ORDINALITY AS module(value, rank)
                    WHERE module.value <> 'modules_studio_deliverables')
             WHERE disabled_modules::text LIKE '%"modules_studio_deliverables"%'
               AND disabled_modules::text NOT LIKE '%"modules_studio_decks"%'
            SQL);
        $this->withoutRoute('core_users', 'disabled_modules', 'modules_studio_decks');
    }

    /** Étape 8 : l'entrée du menu des présentations, partout où elle est nommée. */
    private function menu(): void
    {
        $this->withoutRoute('core_users', 'hidden_nav_items', 'suite_studio_decks');

        // Section → liste ordonnée de routes : l'entrée sort de chaque liste.
        $this->addSql(<<<'SQL'
            UPDATE core_settings
               SET "value" = (
                   SELECT COALESCE(json_object_agg(section.key,
                              CASE WHEN json_typeof(section.value) = 'array'
                                   THEN (SELECT COALESCE(json_agg(item.value ORDER BY item.rank), '[]'::json)
                                           FROM json_array_elements_text(section.value) WITH ORDINALITY AS item(value, rank)
                                          WHERE item.value <> 'suite_studio_decks')
                                   ELSE section.value END), '{}'::json)
                     FROM json_each(core_settings."value"::json) AS section)::text
             WHERE setting_key = 'nav_item_order' AND "value" LIKE '%"suite_studio_decks"%'
            SQL);
        // Route → alias : la clé disparaît.
        $this->addSql(<<<'SQL'
            UPDATE core_settings SET "value" = ("value"::jsonb - 'suite_studio_decks')::text
             WHERE setting_key = 'nav_item_aliases' AND "value" LIKE '%"suite_studio_decks"%'
            SQL);
    }

    /** Étape 9 : ce qui ne sert plus. */
    private function dropDecks(): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF to_regclass('messenger_messages') IS NOT NULL THEN
                    DELETE FROM messenger_messages WHERE body LIKE '%PurgeTrashedDecksMessage%';
                END IF;
            END $$
            SQL);

        $this->addSql('DROP TABLE core_deck_share_links');
        $this->addSql('DROP TABLE core_decks');
        $this->addSql('DROP TABLE core_deck_categories');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_deck_id');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_deck_category_id');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_deck_share_link_id');
        $this->addSql('DROP TABLE core_studio_deck_merge');
        $this->addSql('DROP TABLE core_studio_deck_category_merge');
    }

    /** Une valeur retirée d'une liste JSON d'une colonne, dans l'ordre des autres. */
    private function withoutRoute(string $table, string $column, string $value): void
    {
        $this->addSql(sprintf(
            <<<'SQL'
                UPDATE %1$s
                   SET %2$s = (
                       SELECT COALESCE(json_agg(entry.value ORDER BY entry.rank), '[]'::json)
                         FROM json_array_elements_text(%1$s.%2$s) WITH ORDINALITY AS entry(value, rank)
                        WHERE entry.value <> '%3$s')
                 WHERE %2$s::text LIKE '%%"%3$s"%%'
                SQL,
            $table,
            $column,
            $value,
        ));
    }
}
