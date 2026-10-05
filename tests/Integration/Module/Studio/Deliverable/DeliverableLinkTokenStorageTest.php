<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Sharing\ShareToken;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function basename;
use function hash;
use function json_decode;
use function mb_strlen;
use function parse_url;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * The secret in a reading address is not readable in the table.
 *
 * A backup or a SQL log used to be a list of working addresses. The token is
 * now encrypted at rest and looked up by its fingerprint; the links window
 * still shows the address, because the application decrypts it.
 */
final class DeliverableLinkTokenStorageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', DeliverableLink::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deliverable::class))->execute();

        parent::tearDown();
    }

    public function testTheTokenIsEncryptedInTheTableAndTheAddressStillWorks(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/deliverables/create', ['title' => 'Secret', 'scope' => 'shared']);
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['shared'][0]['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/create', $id), []);
        self::assertResponseIsSuccessful();
        $token = basename((string) parse_url(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['links'][0]['url'], PHP_URL_PATH));

        $stored = $this->entityManager->getConnection()->fetchAssociative('SELECT token, token_hash FROM core_studio_deliverable_links');
        self::assertIsArray($stored);
        self::assertNotSame($token, $stored['token'], 'A dump must not hold the address.');
        self::assertStringNotContainsString($token, $stored['token']);
        self::assertGreaterThan(mb_strlen($token), mb_strlen($stored['token']));
        self::assertSame(hash('sha256', $token), $stored['token_hash']);

        // Found by the fingerprint, and read back in clear for the window.
        $this->entityManager->clear();
        $link = self::getContainer()->get(DeliverableLinkRepository::class)->findByToken($token);
        self::assertNotNull($link);
        self::assertSame($token, $link->getToken());
        self::assertNull(self::getContainer()->get(DeliverableLinkRepository::class)->findByToken(ShareToken::hash($token)), 'The fingerprint is not an address.');

        $this->client->request('GET', '/deliverables/'.$token);
        self::assertResponseIsSuccessful();
    }

    public function testALinkNobodyOpenedIsDeletedAndAnOpenedOneIsOnlyRevoked(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/deliverables/create', ['title' => 'Liens', 'scope' => 'shared']);
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['shared'][0]['id'];
        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/create', $id), ['label' => 'Jamais ouvert']);
        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/create', $id), ['label' => 'Déjà ouvert']);

        $this->entityManager->clear();
        [$unopened, $opened] = $this->entityManager->getRepository(DeliverableLink::class)->findBy([], ['id' => 'ASC']);
        $opened->touch(new DateTimeImmutable());
        $this->entityManager->flush();
        [$unopenedId, $openedId] = [$unopened->getId(), $opened->getId()];

        // An opened link is not deleted: its row says who could read.
        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/%d/delete', $id, $openedId));
        self::assertResponseStatusCodeSame(409);

        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/%d/revoke', $id, $openedId));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/%d/delete', $id, $unopenedId));
        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['links']);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(DeliverableLink::class, $unopenedId));
        self::assertNotNull($this->entityManager->find(DeliverableLink::class, $openedId));

        $actions = array_map(
            static fn (AuditLog $log): string => $log->getAction(),
            $this->entityManager->getRepository(AuditLog::class)->findBy(['entityType' => 'DeliverableLink']),
        );
        self::assertContains('deliverable_link.deleted', $actions);
    }

    public function testAnotherDeliverablesLinkCannotBeDeletedThroughThisOne(): void
    {
        foreach (['Un', 'Deux'] as $title) {
            $this->client->jsonRequest('POST', '/backend/studio/deliverables/create', ['title' => $title, 'scope' => 'shared']);
        }

        $rows = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['shared'];
        [$first, $second] = [(int) $rows[0]['id'], (int) $rows[1]['id']];
        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/create', $first), []);
        $this->entityManager->clear();
        $link = $this->entityManager->getRepository(DeliverableLink::class)->findOneBy([]);

        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/%d/delete', $second, $link->getId()));

        self::assertResponseStatusCodeSame(404);
    }
}
