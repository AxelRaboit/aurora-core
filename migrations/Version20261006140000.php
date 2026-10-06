<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

use function mb_strlen;
use function sprintf;

/**
 * Studio presentations become deliverables in the slides format.
 *
 * The two previous steps made room for them (the format, the template, the
 * customer, then the slides of a deliverable); this one moves the data and
 * removes the presentation tables. Written by hand, in SQL: a migration must
 * owe nothing to the classes it makes obsolete.
 *
 * What goes where:
 *
 * 1. **Presentations** become Studio deliverables: no space, no author (a
 *    presentation had none), shared with the team, in the `slides` format.
 *    The description becomes the summary, the theme and its tweaks become
 *    those of the slides; title, template, customer, dates and trash state
 *    are kept as they are. The locale is the `default_locale` setting, or
 *    `fr` when there is none. The ids come from the deliverable sequence, and
 *    a mapping table (`core_studio_deck_merge`) keeps the correspondence for
 *    the duration of the migration.
 * 2. **Categories** join the deliverable categories by name, ignoring case
 *    and surrounding spaces: "Lancement" matches "lancement". An unknown name
 *    creates a deliverable category, placed after the others in the order it
 *    had.
 * 3. **Slides stay in their table**, `core_deck_slides`, with their ids and
 *    their sequence: `deliverable_id` is filled from the mapping table, then
 *    `deck_id` goes away and `deliverable_id` becomes required. It is the
 *    simplest choice that keeps the ids: a new table would have needed a copy
 *    and a sequence reset for the same result. The table name will follow
 *    when the slide engine classes move.
 * 4. **Share links** become reading links, column for column: the encrypted
 *    token is copied byte for byte (the encryption does not depend on the
 *    table), with its hash, its label, its dates, its counter and its
 *    password. An address already sent keeps its token; `/decks/{jeton}`
 *    redirects it to `/deliverables/{jeton}`.
 * 5. **The "deck" zones of the site pages** (and of their revisions, and of
 *    the deliverable grids that carry the same key) keep their type but now
 *    name a deliverable: `deckId` becomes `deliverableId`, with the id of the
 *    deliverable that took the presentation's place. A zone that named a
 *    presentation that no longer exists names nothing.
 * 6. **Privileges** `studio.decks.*` become `studio.deliverables.*`, without
 *    duplicates; `studio.deck_categories.manage` goes away (deliverable
 *    categories follow the right to edit them).
 * 7. **The presentations toggle** goes away, from the global setting as well
 *    as from each person's disabled modules. Whoever had presentations
 *    without deliverables gets deliverables: otherwise their presentations
 *    would vanish with the module that held them.
 * 8. **The menu**: `suite_studio_decks` leaves the hidden entries, the order
 *    and the aliases of the menu (same trap as `Version20260912210000`: the
 *    setting keys do not change, the route names are in their value).
 * 9. The presentation tables and sequences are dropped, along with the
 *    mapping tables and any presentation purge message still queued.
 *
 * Irreversible: the presentations no longer exist to go back to.
 */
final class Version20261006140000 extends AbstractMigration
{
    /** The privileges that get renamed, without the final dot. */
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

    /** Steps 1 and 2: categories first, then presentations. */
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
        // A single new name per unknown name, even if two presentation
        // categories only differed by case: the first, in the order they were
        // sorted, gives the name and the color.
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

    /** Step 3: slides change owner, not table. */
    private function slides(): void
    {
        $this->addSql('UPDATE core_deck_slides slide SET deliverable_id = deck_map.deliverable_id FROM core_studio_deck_merge deck_map WHERE slide.deck_id = deck_map.deck_id');
        // None should be left without an owner: `deck_id` was required until
        // the previous step, and a deliverable slide already has its own.
        // Stated anyway, before the constraint.
        $this->addSql('DELETE FROM core_deck_slides WHERE deliverable_id IS NULL');
        $this->addSql('ALTER TABLE core_deck_slides DROP CONSTRAINT IF EXISTS FK_5F851CE8111948DC');
        $this->addSql('DROP INDEX IF EXISTS IDX_5F851CE8111948DC');
        $this->addSql('ALTER TABLE core_deck_slides DROP deck_id');
        $this->addSql('ALTER TABLE core_deck_slides ALTER deliverable_id SET NOT NULL');
    }

    /** Step 4: share links, column for column, encrypted token included. */
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
     * Step 5: `deckId` becomes `deliverableId` in the grids.
     *
     * The JSON text rather than the JSON functions: a zone can be nested in
     * another (a stack carries children), and the key is the same at every
     * depth. First the known ids, one by one; then the ones that no longer
     * name anything are emptied, so they never point by chance to the
     * deliverable that has the same number; finally the key of the zones that
     * had no id.
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
     * Step 6: privileges, renamed without duplicates and in the order the
     * person had received them.
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

    /** Step 7: the presentations toggle, global and per person. */
    private function toggle(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE core_settings SET "value" = '1'
             WHERE setting_key = 'modules_studio_deliverables' AND "value" = '0'
               AND EXISTS (SELECT 1 FROM core_settings decks WHERE decks.setting_key = 'modules_studio_decks' AND decks."value" = '1')
            SQL);
        $this->addSql("DELETE FROM core_settings WHERE setting_key = 'modules_studio_decks'");

        // Disabled for the person: `disabled_modules` lists what they do not
        // have. With presentations on and deliverables off, they get
        // deliverables.
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

    /** Step 8: the presentations menu entry, wherever it is named. */
    private function menu(): void
    {
        $this->withoutRoute('core_users', 'hidden_nav_items', 'suite_studio_decks');

        // Section → ordered list of routes: the entry leaves every list.
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
        // Route → alias: the key goes away.
        $this->addSql(<<<'SQL'
            UPDATE core_settings SET "value" = ("value"::jsonb - 'suite_studio_decks')::text
             WHERE setting_key = 'nav_item_aliases' AND "value" LIKE '%"suite_studio_decks"%'
            SQL);
    }

    /** Step 9: what is no longer used. */
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

    /** A value removed from a column's JSON list, keeping the order of the others. */
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
