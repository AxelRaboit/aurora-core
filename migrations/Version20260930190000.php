<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The signature keeps the address its code was sent to.
 *
 * The signed PDF printed the client's current address: if it changed between
 * the signature and the countersignature, the document certified a code sent
 * to a mailbox that had never received it.
 *
 * Existing signatures take the address of the last code consumed on the link
 * they were given through, when that code still exists.
 */
final class Version20260930190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contracts: keep the address the signature code was sent to';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_contract_signatures ADD challenge_sent_to VARCHAR(180) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE core_contract_signatures s
            SET challenge_sent_to = (
                SELECT c.sent_to
                FROM core_contract_signature_challenges c
                JOIN core_contract_access_links l ON l.id = c.link_id
                WHERE l.selector = s.link_selector
                  AND c.consumed_at IS NOT NULL
                ORDER BY c.consumed_at DESC
                LIMIT 1
            )
            WHERE s.challenge_verified_at IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_contract_signatures DROP challenge_sent_to');
    }
}
