<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Aurora\Core\Encryption\Service\EncryptionService;
use Aurora\Module\Notes\Markdown\Service\EditorBlocksToMarkdown;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

use function base64_decode;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function mb_trim;
use function sprintf;

/**
 * The notes of the customer spaces move into the Notes module, and their
 * table goes.
 *
 * - **A team note** (`shared`) goes into the note space of its customer space:
 *   one note space per customer space, created here when it does not exist
 *   yet, managed by `studio.customer_space`, without an owner, open to its
 *   members only. The space's team is enrolled the way `SpaceNoteSpaceSync`
 *   does it afterwards: the lead manages, a member writes.
 * - **A personal note** goes into its author's personal note space (created
 *   when the author has none yet), in a folder named after the customer
 *   space - one folder per author and per customer space.
 * - **A personal note whose author's account is gone** belonged to nobody and
 *   was read by nobody: it is dropped, and the migration says how many.
 *
 * The body was Editor.js blocks; it becomes Markdown through
 * {@see EditorBlocksToMarkdown}. Title, body, the note space's name and the
 * folder's name are encrypted here, with the application key the
 * `encrypted_text` type uses: a missing key stops the migration before
 * anything is written.
 *
 * What is kept: the title, the body, the author, both dates, the Craft
 * document a note was copied from, and the order of the wall (pinned first,
 * then the most recently touched) as the notes' order. A pinned note becomes
 * a favourite of its author. **What is lost, on purpose**: the colour of the
 * post-it and the author's label (the name as it was written when the note
 * was taken) have no equivalent in a Markdown note; the author is still known
 * by the account while it exists. An image in a body stays a document of the
 * library, cited by its address, but the library no longer counts the note
 * among its usages.
 *
 * Runs in `postUp`, after the table has been locked, so that no note is
 * written while it moves; the old table and its sequence are dropped at the
 * end, in the same transaction. Do not run it with `--dry-run`: Doctrine runs
 * `postUp` in a dry run too.
 *
 * Irreversible: the old table is gone, and a Markdown note does not turn
 * back into blocks.
 */
final class Version20261006190000 extends AbstractMigration
{
    private const string MANAGED_BY = 'studio.customer_space';

    public function getDescription(): string
    {
        return 'Customer space notes move into the Notes module; core_studio_space_notes is dropped';
    }

    public function up(Schema $schema): void
    {
        // Nothing may write a note while they move: postUp reads, rewrites,
        // then drops the table inside this transaction.
        $this->addSql('LOCK TABLE core_studio_space_notes IN EXCLUSIVE MODE');
    }

    public function postUp(Schema $schema): void
    {
        $encryption = $this->encryption();
        $converter = new EditorBlocksToMarkdown();
        $now = $this->now();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT n.id, n.space_id, n.author_id, n.title, n.body, n.pinned, n.visibility, n.craft_document_id, n.created_at, n.updated_at, s.name AS space_name, s.note_space_id
               FROM core_studio_space_notes n
               JOIN core_studio_customer_spaces s ON s.id = n.space_id
              ORDER BY n.space_id, n.pinned DESC, n.updated_at DESC, n.id DESC',
        );

        $teamSpaces = [];
        $personalSpaces = [];
        $folders = [];
        $nextPosition = [];
        $counts = ['shared' => 0, 'personal' => 0, 'dropped' => 0, 'noteSpaces' => 0, 'folders' => 0, 'favourites' => 0];

        foreach ($rows as $row) {
            $authorId = null === $row['author_id'] ? null : (int) $row['author_id'];
            $personal = 'personal' === $row['visibility'];

            if ($personal && null === $authorId) {
                ++$counts['dropped'];

                continue;
            }

            $customerSpaceId = (int) $row['space_id'];
            $spaceName = (string) $row['space_name'];

            if ($personal) {
                $noteSpaceId = $personalSpaces[$authorId] ??= $this->personalSpaceOf($authorId, $now);
                $folderKey = $authorId.':'.$customerSpaceId;

                if (!isset($folders[$folderKey])) {
                    $folders[$folderKey] = $this->insertFolder($authorId, $noteSpaceId, $encryption->encrypt($spaceName), $this->rootPosition($noteSpaceId, $nextPosition), $now);
                    ++$counts['folders'];
                }

                $folderId = $folders[$folderKey];
                $positionKey = 'folder:'.$folderId;
                $nextPosition[$positionKey] ??= 0;
                $position = $nextPosition[$positionKey]++;
                ++$counts['personal'];
            } else {
                if (!isset($teamSpaces[$customerSpaceId])) {
                    $teamSpaces[$customerSpaceId] = $this->teamSpaceOf($customerSpaceId, null === $row['note_space_id'] ? null : (int) $row['note_space_id'], $encryption->encrypt($spaceName), $now, $counts);
                }

                $noteSpaceId = $teamSpaces[$customerSpaceId];
                $folderId = null;
                $position = $this->rootPosition($noteSpaceId, $nextPosition);
                ++$counts['shared'];
            }

            $body = json_decode((string) $row['body'], true);
            $content = $converter->convert(is_array($body) ? $body : []);

            $noteId = $this->nextId('seq_core_notes_markdown_note_id');
            $this->connection->insert('core_notes_markdown_notes', [
                'id' => $noteId,
                'user_id' => $authorId,
                'title' => $encryption->encrypt((string) $row['title']),
                'content' => '' === mb_trim($content) ? null : $encryption->encrypt($content),
                'tags' => '[]',
                'position' => $position,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'folder_id' => $folderId,
                'space_id' => $noteSpaceId,
                'craft_document_id' => $row['craft_document_id'],
                'cover_position' => 50,
                'appearance' => 'plain',
                'version' => 1,
                'is_template' => false,
            ], ['is_template' => 'boolean']);

            if ((bool) $row['pinned'] && null !== $authorId) {
                $this->connection->insert('core_notes_favorites', [
                    'id' => $this->nextId('seq_core_notes_favorite_id'),
                    'user_id' => $authorId,
                    'note_id' => $noteId,
                    'created_at' => $now,
                ]);
                ++$counts['favourites'];
            }
        }

        $this->write(sprintf(
            '<info>Space notes moved: %d team notes into %d new note spaces, %d personal notes into %d folders, %d favourites from pins, %d authorless personal notes dropped.</info>',
            $counts['shared'],
            $counts['noteSpaces'],
            $counts['personal'],
            $counts['folders'],
            $counts['favourites'],
            $counts['dropped'],
        ));

        $this->connection->executeStatement('DROP TABLE core_studio_space_notes');
        $this->connection->executeStatement('DROP SEQUENCE seq_core_space_note_id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Space notes now live in the Notes module, in Markdown; the old table is gone.');
    }

    /**
     * The note space of a customer space: the one it points at when it is
     * still managed and alive, a new one otherwise, with the team enrolled.
     *
     * @param array<string, int> $counts
     */
    private function teamSpaceOf(int $customerSpaceId, ?int $linked, string $encryptedName, string $now, array &$counts): int
    {
        if (null !== $linked) {
            $alive = $this->connection->fetchOne(
                'SELECT id FROM core_notes_spaces WHERE id = :id AND managed_by IS NOT NULL AND deleted_at IS NULL',
                ['id' => $linked],
            );

            if (false !== $alive) {
                $this->enrolTeam($customerSpaceId, $linked);

                return $linked;
            }
        }

        $id = $this->nextId('seq_core_notes_space_id');
        $this->connection->insert('core_notes_spaces', [
            'id' => $id,
            'name' => $encryptedName,
            'access' => 'members',
            'default_role' => 'reader',
            'indexable' => false,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'managed_by' => self::MANAGED_BY,
        ], ['indexable' => 'boolean']);
        $this->connection->update('core_studio_customer_spaces', ['note_space_id' => $id], ['id' => $customerSpaceId]);
        ++$counts['noteSpaces'];

        $this->enrolTeam($customerSpaceId, $id);

        return $id;
    }

    /** The lead manages, a member writes: what `SpaceNoteSpaceSync` does afterwards. */
    private function enrolTeam(int $customerSpaceId, int $noteSpaceId): void
    {
        /** @var list<array{user_id: int, role: string}> $members */
        $members = $this->connection->fetchAllAssociative(
            'SELECT m.user_id, m.role FROM core_studio_customer_space_members m
              WHERE m.space_id = :space
                AND NOT EXISTS (SELECT 1 FROM core_notes_space_members e WHERE e.space_id = :noteSpace AND e.user_id = m.user_id)',
            ['space' => $customerSpaceId, 'noteSpace' => $noteSpaceId],
        );

        foreach ($members as $member) {
            $this->connection->insert('core_notes_space_members', [
                'id' => $this->nextId('seq_core_notes_space_member_id'),
                'space_id' => $noteSpaceId,
                'user_id' => (int) $member['user_id'],
                'role' => 'lead' === $member['role'] ? 'manager' : 'editor',
            ]);
        }
    }

    /** The personal note space of an account, opened when it has none yet. */
    private function personalSpaceOf(int $userId, string $now): int
    {
        $existing = $this->connection->fetchOne('SELECT id FROM core_notes_spaces WHERE personal_user_id = :user', ['user' => $userId]);

        if (false !== $existing) {
            return (int) $existing;
        }

        $id = $this->nextId('seq_core_notes_space_id');
        $this->connection->insert('core_notes_spaces', [
            'id' => $id,
            'personal_user_id' => $userId,
            'owner_id' => $userId,
            'access' => 'private',
            'default_role' => 'reader',
            'indexable' => false,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['indexable' => 'boolean']);

        return $id;
    }

    private function insertFolder(int $userId, int $noteSpaceId, string $encryptedName, int $position, string $now): int
    {
        $id = $this->nextId('seq_core_notes_markdown_folder_id');
        $this->connection->insert('core_notes_markdown_folders', [
            'id' => $id,
            'user_id' => $userId,
            'name' => $encryptedName,
            'position' => $position,
            'created_at' => $now,
            'updated_at' => $now,
            'space_id' => $noteSpaceId,
        ]);

        return $id;
    }

    /**
     * The next rank at the root of a note space, past its notes and folders
     * alike: they share one order among siblings.
     *
     * @param array<string, int> $nextPosition
     */
    private function rootPosition(int $noteSpaceId, array &$nextPosition): int
    {
        $key = 'root:'.$noteSpaceId;

        if (!isset($nextPosition[$key])) {
            $notes = $this->connection->fetchOne('SELECT MAX(position) FROM core_notes_markdown_notes WHERE space_id = :space AND folder_id IS NULL', ['space' => $noteSpaceId]);
            $folders = $this->connection->fetchOne('SELECT MAX(position) FROM core_notes_markdown_folders WHERE space_id = :space AND parent_id IS NULL', ['space' => $noteSpaceId]);
            $nextPosition[$key] = max(null === $notes || false === $notes ? -1 : (int) $notes, null === $folders || false === $folders ? -1 : (int) $folders) + 1;
        }

        return $nextPosition[$key]++;
    }

    private function nextId(string $sequence): int
    {
        return (int) $this->connection->fetchOne(sprintf("SELECT nextval('%s')", $sequence));
    }

    private function now(): string
    {
        return (string) $this->connection->fetchOne('SELECT LOCALTIMESTAMP(0)');
    }

    private function encryption(): EncryptionService
    {
        $key = $_ENV['AURORA_ENCRYPTION_KEY'] ?? $_SERVER['AURORA_ENCRYPTION_KEY'] ?? getenv('AURORA_ENCRYPTION_KEY');

        if (!is_string($key) || '' === $key || false === base64_decode($key, true)) {
            throw new RuntimeException('AURORA_ENCRYPTION_KEY is not available to the migration: it cannot encrypt the notes it moves.');
        }

        return new EncryptionService($key);
    }
}
