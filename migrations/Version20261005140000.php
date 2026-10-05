<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Aurora\Core\Encryption\Service\EncryptionService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

use function base64_decode;
use function getenv;
use function hash;
use function is_string;

/**
 * Reading addresses of deliverables: the token is encrypted at rest and found
 * by its SHA-256 fingerprint.
 *
 * Until now the secret sat in clear in the table: a backup or a SQL log was a
 * list of working addresses, where the access links of a space were already
 * hashed. The address still has to be shown again in the links window, so the
 * token is ENCRYPTED rather than hashed, and a `token_hash` column carries the
 * lookup.
 *
 * No address changes: every token is read as it is, fingerprinted, then
 * encrypted, so what was already sent keeps working.
 *
 * The encryption runs in `postUp` with the application key read from the
 * environment, the same one the `encrypted_string` type uses. A missing key
 * stops the migration before anything is rewritten.
 */
final class Version20261005140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deliverable links: token encrypted at rest, looked up by its hash';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_346bb9685f37a13b');
        $this->addSql('ALTER TABLE core_studio_deliverable_links ALTER token TYPE VARCHAR(255)');
        $this->addSql("COMMENT ON COLUMN core_studio_deliverable_links.token IS '(DC2Type:encrypted_string)'");
        $this->addSql('ALTER TABLE core_studio_deliverable_links ADD token_hash VARCHAR(64) DEFAULT NULL');
        // Computed by the database from the clear token, before postUp encrypts it.
        $this->addSql("UPDATE core_studio_deliverable_links SET token_hash = encode(sha256(convert_to(token, 'UTF8')), 'hex')");
        $this->addSql('ALTER TABLE core_studio_deliverable_links ALTER token_hash SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_deliverable_link_token_hash ON core_studio_deliverable_links (token_hash)');
    }

    public function postUp(Schema $schema): void
    {
        $encryption = $this->encryption();

        /** @var list<array{id: int, token: string, token_hash: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, token, token_hash FROM core_studio_deliverable_links');

        foreach ($rows as $row) {
            // Already encrypted by an earlier, interrupted run: its hash would not match the clear form.
            if (hash('sha256', $row['token']) !== $row['token_hash']) {
                continue;
            }

            $this->connection->update('core_studio_deliverable_links', ['token' => $encryption->encrypt($row['token'])], ['id' => $row['id']]);
        }
    }

    public function preDown(Schema $schema): void
    {
        $encryption = $this->encryption();

        /** @var list<array{id: int, token: string, token_hash: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, token, token_hash FROM core_studio_deliverable_links');

        foreach ($rows as $row) {
            $clear = $encryption->decrypt($row['token']);

            if (null === $clear) {
                throw new RuntimeException('A deliverable link token cannot be decrypted: the key is not the one that encrypted it.');
            }

            $this->connection->update('core_studio_deliverable_links', ['token' => $clear], ['id' => $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_deliverable_link_token_hash');
        $this->addSql('ALTER TABLE core_studio_deliverable_links DROP token_hash');
        $this->addSql('COMMENT ON COLUMN core_studio_deliverable_links.token IS NULL');
        $this->addSql('ALTER TABLE core_studio_deliverable_links ALTER token TYPE VARCHAR(64)');
        $this->addSql('CREATE UNIQUE INDEX uniq_346bb9685f37a13b ON core_studio_deliverable_links (token)');
    }

    private function encryption(): EncryptionService
    {
        $key = $_ENV['AURORA_ENCRYPTION_KEY'] ?? $_SERVER['AURORA_ENCRYPTION_KEY'] ?? getenv('AURORA_ENCRYPTION_KEY');

        if (!is_string($key) || '' === $key || false === base64_decode($key, true)) {
            throw new RuntimeException('AURORA_ENCRYPTION_KEY is not available to the migration: it cannot encrypt the tokens.');
        }

        return new EncryptionService($key);
    }
}
