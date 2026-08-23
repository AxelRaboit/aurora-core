<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The published feed, which is the one unauthenticated thing this module serves.
 *
 * So the tests are about who can read it rather than about what it contains: the
 * URL is the credential, and the properties that make that acceptable are
 * opt-in, unguessable and revocable.
 */
final class PlanningFeedTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $admin;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /**
     * No calendar publishes anything until somebody asks.
     *
     * The property that makes an unauthenticated route acceptable at all.
     */
    public function testACalendarPublishesNothingByDefault(): void
    {
        $planning = $this->calendar();

        self::assertFalse($planning->hasFeed());
        self::assertNull($planning->getFeedToken());
    }

    public function testAnUnknownTokenIsNotFound(): void
    {
        // 404 and never 403: a wrong token must not reveal that a right one
        // exists.
        $this->client->request('GET', '/planning/feed/'.str_repeat('x', 43).'.ics');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFeedIsReadableWithoutSigningIn(): void
    {
        $planning = $this->published();

        // No `loginUser`. A phone fetches this on a timer with no session, which
        // is the whole reason the route exists outside the firewall's rules.
        $this->client->request('GET', $this->feedUrl($planning));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'text/calendar',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $body);
    }

    public function testItCarriesTheEventsAndTheReminders(): void
    {
        $planning = $this->published();

        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle('Recette; client, et autres');
        $event->setSpan(new DateTimeImmutable('2026-09-01 10:00'), new DateTimeImmutable('2026-09-01 11:00'));
        $this->entityManager->persist($event);

        $reminder = new PlanningReminder();
        $reminder->setPlanning($planning);
        $reminder->setTitle('Relancer');
        $reminder->setDueAt(new DateTimeImmutable('2026-09-02 09:00'));
        $this->entityManager->persist($reminder);

        $this->entityManager->flush();
        $this->created[] = [PlanningEvent::class, (int) $event->getId()];
        $this->created[] = [PlanningReminder::class, (int) $reminder->getId()];

        // Cleared so the controller loads the calendar rather than finding the
        // instance this test built. That instance's `events` is the ArrayCollection
        // its constructor made - `setPlanning` sets the owning side only - so it
        // would read as empty here and be full in production, where the feed is a
        // separate request. Clearing makes the test do what a fetch does.
        $token = (string) $planning->getFeedToken();
        $this->entityManager->clear();

        $this->client->request('GET', $this->urlGenerator->generate('planning_feed_show', ['token' => $token]));
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('BEGIN:VEVENT', $body);
        // A reminder is a VTODO, not an event: it has a due date and a completion
        // state, and flattening it would lose the checkbox that makes it one.
        self::assertStringContainsString('BEGIN:VTODO', $body);
        self::assertStringContainsString('STATUS:NEEDS-ACTION', $body);
        // The semicolon and comma are escaped, as the format requires.
        self::assertStringContainsString('SUMMARY:Recette\; client\\, et autres', $body);
    }

    /**
     * Revoking breaks the address, which is the only way to un-share a URL.
     */
    public function testRevokingMakesTheAddressStopWorking(): void
    {
        $planning = $this->published();
        $url = $this->feedUrl($planning);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_planning_calendars_feed_revoke', ['id' => $planning->getId()]),
        );
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Publishing again replaces the address rather than returning the old one.
     *
     * That is how somebody revokes an address they shared too widely without
     * losing the feed, and handing back the same token would answer a different
     * question.
     */
    public function testPublishingAgainReplacesTheAddress(): void
    {
        $planning = $this->published();
        $first = $planning->getFeedToken();

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_planning_calendars_feed', ['id' => $planning->getId()]),
        );
        self::assertResponseIsSuccessful();

        $this->entityManager->refresh($planning);

        self::assertNotSame($first, $planning->getFeedToken());
        self::assertTrue($planning->hasFeed());
    }

    public function testTheTokenIsLongEnoughToBeUnguessable(): void
    {
        // 32 random bytes as base64url, so 43 characters. Written down because a
        // shorter token would still work and would still be wrong.
        self::assertSame(43, mb_strlen((string) $this->published()->getFeedToken()));
    }

    /**
     * The response says not to store it anywhere in between.
     *
     * The URL is a secret, and a shared cache holding this would serve it to
     * whoever asks next.
     */
    public function testTheResponseIsNotCacheableByAnythingInBetween(): void
    {
        $planning = $this->published();

        $this->client->request('GET', $this->feedUrl($planning));

        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    private function feedUrl(Planning $planning): string
    {
        return $this->urlGenerator->generate('planning_feed_show', ['token' => $planning->getFeedToken()]);
    }

    private function calendar(string $name = 'Flux'): Planning
    {
        $planning = new Planning();
        $planning->setName($name);
        $planning->setOwner($this->admin);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $planning->getId()];

        return $planning;
    }

    private function published(): Planning
    {
        $planning = $this->calendar();
        $planning->publishFeed();
        $this->entityManager->flush();

        return $planning;
    }
}
