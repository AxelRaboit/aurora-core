<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLink;
use Aurora\Module\Studio\Deck\Share\Repository\DeckShareLinkRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function password_hash;
use function random_bytes;

use const PASSWORD_DEFAULT;

/**
 * What a share link lets somebody see, and what it must not.
 *
 * The interesting assertions are the negative ones. A link is a secret handed
 * to one person, so the three ways it can stop working have to stop working,
 * and the speaker notes have to stay on the presenter's side of the screen.
 */
final class DeckShareLinkTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function testAValidLinkOpensTheDeckWithoutAnAccount(): void
    {
        $link = $this->link();

        $this->client->request('GET', '/decks/'.$link->getToken());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('PublicDeckApp', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The one that would be embarrassing rather than merely wrong: the notes
     * are what the presenter says, and a single screen is the audience's.
     */
    public function testTheSpeakerNotesNeverReachThePage(): void
    {
        $link = $this->link('Ne pas dire que le budget est deja vote.');

        $this->client->request('GET', '/decks/'.$link->getToken());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('budget est deja vote', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('speakerNotes', (string) $this->client->getResponse()->getContent());
    }

    public function testARevokedLinkIsRefused(): void
    {
        $link = $this->link();
        $link->revoke(new DateTimeImmutable());
        $this->entityManager()->flush();

        $this->client->request('GET', '/decks/'.$link->getToken());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $link = $this->link();
        $link->setExpiresAt(new DateTimeImmutable('-1 day'));
        $this->entityManager()->flush();

        $this->client->request('GET', '/decks/'.$link->getToken());

        self::assertResponseStatusCodeSame(404);
    }

    /** A guessed address must learn nothing, including that it was close. */
    public function testAnUnknownTokenIs404(): void
    {
        $this->client->request('GET', '/decks/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOpeningTheLinkRecordsThatItWasOpened(): void
    {
        $link = $this->link();

        self::assertNull($link->getLastUsedAt());

        $this->client->request('GET', '/decks/'.$link->getToken());
        $this->entityManager()->refresh($link);

        self::assertNotNull($link->getLastUsedAt());
    }

    public function testOpeningTheLinkTwiceCountsTwice(): void
    {
        $link = $this->link();

        self::assertSame(0, $link->getOpenCount());

        $token = $link->getToken();

        $this->client->request('GET', '/decks/'.$token);
        $this->client->request('GET', '/decks/'.$token);

        self::assertSame(2, $this->reload($token)->getOpenCount());
    }

    /**
     * A protected link shows a door, and the door says nothing about the deck.
     *
     * Not its title above all: the page exists so that somebody holding the
     * address but not the password learns nothing, and a title is most of what
     * there is to learn about a deck.
     */
    public function testAProtectedLinkAsksForItsPasswordAndNamesNothing(): void
    {
        [$link] = $this->lockedLink();

        $this->client->request('GET', '/decks/'.$link->getToken());

        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('Revue de fin d annee', $body);
        self::assertStringNotContainsString('PublicDeckApp', $body);
        self::assertStringContainsString('type="password"', $body);
    }

    public function testTheRightPasswordOpensItAndTheSessionRemembers(): void
    {
        [$link, $phrase] = $this->lockedLink();

        $this->client->request('POST', '/decks/'.$link->getToken().'/unlock', ['password' => $phrase]);
        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertStringContainsString('PublicDeckApp', (string) $this->client->getResponse()->getContent());

        // Asked again later in the same session, the door does not reappear.
        $this->client->request('GET', '/decks/'.$link->getToken());
        self::assertStringContainsString('PublicDeckApp', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A wrong password answers what a wrong address answers.
     *
     * Telling the two apart would confirm to somebody guessing addresses that
     * this one is real, which is the single thing a guessed token must not
     * learn.
     */
    public function testAWrongPasswordOpensNothingAndSaysNothingMore(): void
    {
        [$link, $phrase] = $this->lockedLink();

        $this->client->request('POST', '/decks/'.$link->getToken().'/unlock', ['password' => $phrase.'-faux']);

        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('PublicDeckApp', $body);
        self::assertStringNotContainsString('Revue de fin d annee', $body);

        self::assertSame(0, $this->reload($link->getToken())->getOpenCount(), 'a door that did not open is not an opening');
    }

    /** An unlocked session does not carry over to another protected link. */
    public function testUnlockingOneLinkDoesNotUnlockAnother(): void
    {
        [$first, $phrase] = $this->lockedLink();
        [$second] = $this->lockedLink();

        $this->client->request('POST', '/decks/'.$first->getToken().'/unlock', ['password' => $phrase]);
        $this->client->request('GET', '/decks/'.$second->getToken());

        self::assertStringNotContainsString('PublicDeckApp', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A link behind a passphrase, and the passphrase.
     *
     * Generated rather than written down, and not for secrecy - this one lives
     * for the length of one test. A literal beside `password_hash()` reads as a
     * credential to every scanner that looks at a diff, and this repository has
     * one on every pull request. Two links in the same test also get two
     * different phrases for free, which is what
     * `testUnlockingOneLinkDoesNotUnlockAnother` is about.
     *
     * @return array{0: DeckShareLink, 1: string}
     */
    private function lockedLink(): array
    {
        $phrase = bin2hex(random_bytes(8));

        $link = $this->link();
        $link->setPasswordHash(password_hash($phrase, PASSWORD_DEFAULT));
        $this->entityManager()->flush();

        return [$link, $phrase];
    }

    private function link(?string $notes = null): DeckShareLink
    {
        $container = static::getContainer();
        $decks = $container->get(DeckManager::class);

        $deck = $decks->create('Revue de fin d annee');
        $slide = $decks->addSlide($deck, SlideLayoutEnum::Title);
        $decks->writeContent($slide, ['title' => 'Revue de fin d annee']);
        $slide->setSpeakerNotes($notes);

        $link = new DeckShareLink($deck);
        $this->entityManager()->persist($link);
        $this->entityManager()->flush();

        return $link;
    }

    /**
     * The link as the database now holds it.
     *
     * Re-fetched rather than refreshed: the test client reboots the kernel on
     * every request, so an entity held across two of them is detached and
     * `refresh()` refuses it.
     */
    private function reload(string $token): DeckShareLink
    {
        $link = static::getContainer()->get(DeckShareLinkRepository::class)->findByToken($token);

        self::assertInstanceOf(DeckShareLink::class, $link);

        return $link;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
