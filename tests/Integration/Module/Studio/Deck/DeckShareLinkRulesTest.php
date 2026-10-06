<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLink;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function array_map;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * A presentation's share links obey the rules every Studio link obeys: a
 * duration or a password the rules refuse is refused (422), and only a link
 * nobody ever opened can be deleted.
 */
final class DeckShareLinkRulesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType IN ('Deck', 'DeckShareLink')", AuditLog::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', DeckShareLink::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deck::class))->execute();

        parent::tearDown();
    }

    /**
     * A deck keeps a journal, as a deliverable does: who made it, and who
     * handed out an address to it.
     */
    public function testCreatingSharingAndTrashingADeckAreWrittenToTheAuditLog(): void
    {
        $deck = $this->deck('Journal');
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck), ['label' => 'Pour le client']);
        self::assertResponseIsSuccessful();
        $this->post(sprintf('/suite/studio/decks/%d/delete', $deck));
        self::assertResponseIsSuccessful();

        $actions = array_map(
            static fn (AuditLog $log): string => $log->getAction(),
            $this->entityManager->getRepository(AuditLog::class)->findBy(['module' => 'studio', 'entityType' => ['Deck', 'DeckShareLink']], ['id' => 'ASC']),
        );

        self::assertSame(['deck.created', 'deck_link.issued', 'deck.trashed'], $actions);
    }

    public function testADurationOrAPasswordTheRulesRefuseCreatesNothing(): void
    {
        $deck = $this->deck('Règles');

        foreach ([['expiresInDays' => 400], ['expiresInDays' => 0], ['expiresInDays' => 7.5], ['password' => str_repeat('a', 73)]] as $payload) {
            $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck), $payload);
            self::assertResponseStatusCodeSame(422, json_encode($payload));
        }

        self::assertSame(0, $this->entityManager->getRepository(DeckShareLink::class)->count([]));

        $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck), ['expiresInDays' => 365, 'password' => str_repeat('a', 72)]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->entityManager->getRepository(DeckShareLink::class)->count([]));
    }

    public function testALinkNobodyOpenedIsDeletedAndAnOpenedOneIsOnlyRevoked(): void
    {
        $deck = $this->deck('Suppression');
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck), ['label' => 'Jamais ouvert']);
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck), ['label' => 'Déjà ouvert']);

        $this->entityManager->clear();
        $links = $this->entityManager->getRepository(DeckShareLink::class)->findBy([], ['id' => 'ASC']);
        [$unopened, $opened] = $links;
        $opened->touch(new DateTimeImmutable());
        $this->entityManager->flush();
        $unopenedId = $unopened->getId();
        $openedId = $opened->getId();

        $this->post(sprintf('/suite/studio/decks/%d/share/%d/delete', $deck, $openedId));
        self::assertResponseStatusCodeSame(409);

        $this->post(sprintf('/suite/studio/decks/%d/share/%d/revoke', $deck, $openedId));
        self::assertResponseIsSuccessful();

        $this->post(sprintf('/suite/studio/decks/%d/share/%d/delete', $deck, $unopenedId));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(DeckShareLink::class, $unopenedId));
        self::assertNotNull($this->entityManager->find(DeckShareLink::class, $openedId), 'an opened link keeps its row, revoked');
    }

    public function testAnotherDecksLinkCannotBeDeletedThroughThisOne(): void
    {
        $mine = $this->deck('Le mien');
        $other = $this->deck('Un autre');
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $other), []);
        $this->entityManager->clear();
        $link = $this->entityManager->getRepository(DeckShareLink::class)->findOneBy([]);

        $this->post(sprintf('/suite/studio/decks/%d/share/%d/delete', $mine, $link->getId()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->entityManager->getRepository(DeckShareLink::class)->count([]));
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body = []): void
    {
        $this->client->jsonRequest('POST', $uri, $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function deck(string $title): int
    {
        $this->post('/suite/studio/decks/create', ['title' => $title]);
        self::assertResponseIsSuccessful();

        return (int) json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['deck']['id'];
    }

    public function testARetiredLinkCanBeHiddenButALiveOneCannot(): void
    {
        $deck = $this->deck('Masquage');
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck), ['label' => 'Retiré']);
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck), ['label' => 'Vivant']);

        $this->entityManager->clear();
        $links = [];
        foreach ($this->entityManager->getRepository(DeckShareLink::class)->findBy([], ['id' => 'ASC']) as $link) {
            $links[$link->getLabel()] = $link;
        }
        $links['Retiré']->touch(new DateTimeImmutable());
        $links['Retiré']->revoke(new DateTimeImmutable());
        $this->entityManager->flush();
        $retired = $links['Retiré']->getId();
        $live = $links['Vivant']->getId();

        $this->post(sprintf('/suite/studio/decks/%d/share/%d/hide', $deck, $live));
        self::assertResponseStatusCodeSame(409);

        $this->post(sprintf('/suite/studio/decks/%d/share/%d/hide', $deck, $retired));
        self::assertResponseIsSuccessful();
        $rows = array_column($this->json()['shareLinks'], 'hidden', 'label');
        self::assertEqualsCanonicalizing(['Retiré' => true, 'Vivant' => false], $rows);

        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(DeckShareLink::class, $retired)->isHidden(), 'The row stays, flagged.');
    }

    public function testAnotherDecksLinkCannotBeHiddenThroughThisOne(): void
    {
        $mine = $this->deck('Le mien');
        $other = $this->deck('Un autre');
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $other), []);
        $this->entityManager->clear();
        $link = $this->entityManager->getRepository(DeckShareLink::class)->findOneBy([]);
        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->post(sprintf('/suite/studio/decks/%d/share/%d/hide', $mine, $link->getId()));

        self::assertResponseStatusCodeSame(404);
    }
}
