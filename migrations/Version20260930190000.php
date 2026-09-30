<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La signature garde l'adresse à laquelle son code a été envoyé.
 *
 * Le PDF signé imprimait l'adresse actuelle du client : changée entre la
 * signature et la contresignature, elle faisait certifier au document un code
 * envoyé à une boîte qui ne l'avait jamais reçu.
 *
 * Les signatures existantes reprennent l'adresse du dernier code consommé sur
 * le lien par lequel elles ont été données, quand il existe encore.
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
