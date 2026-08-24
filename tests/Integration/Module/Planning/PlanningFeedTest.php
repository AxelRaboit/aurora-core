<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Link\Entity\PlanningShareLink;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Link\Manager\PlanningShareLinkManagerInterface;
use Aurora\Module\Planning\Link\Repository\PlanningShareLinkRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
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

    private PlanningShareLinkManagerInterface $links;

    private PlanningShareLinkRepository $linkRepository;

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

        $this->links = static::getContainer()->get(PlanningShareLinkManagerInterface::class);
        $this->linkRepository = static::getContainer()->get(PlanningShareLinkRepository::class);
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
    /**
     * No calendar is reachable until somebody opens an address onto it.
     *
     * The property that makes an unauthenticated route acceptable at all, and it
     * now reads off the link table rather than a column: a calendar with no link is
     * a calendar with no way in.
     */
    public function testACalendarPublishesNothingByDefault(): void
    {
        $planning = $this->calendar();

        self::assertSame([], $this->linkRepository->findForCalendar($planning));
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
        $link = $this->published();

        // No `loginUser`. A phone fetches this on a timer with no session, which
        // is the whole reason the route exists outside the firewall's rules.
        $this->client->request('GET', $this->feedUrl($link));

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
        $planning = $this->calendar();
        $link = $this->link([$planning]);

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
        $token = $link->getToken();
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
        $link = $this->published();
        $url = $this->feedUrl($link);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_planning_links_revoke', ['id' => $link->getId()]),
        );
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * An expiry closes it on its own, with nobody doing anything.
     *
     * The whole point of the table replacing the column: an address handed to an
     * outsider stops working by itself rather than staying open until somebody
     * remembers it.
     */
    public function testAnExpiredLinkStopsWorking(): void
    {
        $link = $this->link([$this->calendar()], PlanningShareLinkModeEnum::Ics, new DateTimeImmutable('-1 hour'));

        $this->client->request('GET', $this->feedUrl($link));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A web token does not answer here, and that is a boundary rather than tidiness.
     *
     * Without it the address sent to one guest for a page would also be a permanent
     * subscription they could add to a phone - a wider grant than the person sharing
     * it chose.
     */
    public function testAWebTokenIsNotAFeed(): void
    {
        $link = $this->link([$this->calendar()], PlanningShareLinkModeEnum::Web);

        $this->client->request('GET', $this->feedUrl($link));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * One address, several calendars, one subscription.
     *
     * The thing a column on the calendar could not express, and the reason somebody
     * outside is not handed two links for one schedule.
     */
    public function testOneLinkCanServeSeveralCalendars(): void
    {
        $first = $this->calendar('Pro');
        $second = $this->calendar('Perso');
        $link = $this->link([$first, $second]);

        $this->event($first, 'Réunion pro');
        $this->event($second, 'Rendez-vous perso');

        $token = $link->getToken();
        $this->entityManager->clear();

        $this->client->request('GET', $this->urlGenerator->generate('planning_feed_show', ['token' => $token]));
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('SUMMARY:Réunion pro', $body);
        self::assertStringContainsString('SUMMARY:Rendez-vous perso', $body);
        // One calendar, named after the link rather than after whichever calendar
        // happened to be added first.
        self::assertSame(1, mb_substr_count($body, 'BEGIN:VCALENDAR'));
        self::assertStringContainsString('X-WR-CALNAME:Test', $body);
    }

    /** So a list of links can say which ones have never been opened. */
    public function testFetchingRecordsThatTheLinkWasUsed(): void
    {
        $link = $this->published();
        self::assertNull($link->getLastUsedAt());

        $this->client->request('GET', $this->feedUrl($link));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $stored = $this->linkRepository->findByToken($link->getToken());

        self::assertInstanceOf(PlanningShareLinkInterface::class, $stored);
        self::assertNotNull($stored->getLastUsedAt());
    }

    /**
     * Publishing again replaces the address rather than returning the old one.
     *
     * That is how somebody revokes an address they shared too widely without
     * losing the feed, and handing back the same token would answer a different
     * question.
     */
    /**
     * Two links onto the same calendar are two addresses, not one replaced.
     *
     * The column could hold one, so publishing again meant losing the old address -
     * which was also the only way to rotate it. A row per address separates those:
     * you open another and close the first when you mean to.
     */
    public function testASecondLinkDoesNotReplaceTheFirst(): void
    {
        $planning = $this->calendar();
        $first = $this->link([$planning]);
        $second = $this->link([$planning]);

        self::assertNotSame($first->getToken(), $second->getToken());

        $this->client->request('GET', $this->feedUrl($first));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->feedUrl($second));
        self::assertResponseIsSuccessful();
    }

    public function testTheTokenIsLongEnoughToBeUnguessable(): void
    {
        // 32 random bytes as hex, so 64 characters. Written down because a shorter
        // token would still work and would still be wrong.
        self::assertSame(64, mb_strlen($this->published()->getToken()));
    }

    /**
     * The response says not to store it anywhere in between.
     *
     * The URL is a secret, and a shared cache holding this would serve it to
     * whoever asks next.
     */
    public function testTheResponseIsNotCacheableByAnythingInBetween(): void
    {
        $link = $this->published();

        $this->client->request('GET', $this->feedUrl($link));

        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    private function feedUrl(PlanningShareLinkInterface $link): string
    {
        return $this->urlGenerator->generate('planning_feed_show', ['token' => $link->getToken()]);
    }

    /**
     * A live `.ics` link over one calendar.
     *
     * @param list<PlanningInterface> $calendars
     */
    private function link(
        array $calendars,
        PlanningShareLinkModeEnum $mode = PlanningShareLinkModeEnum::Ics,
        ?DateTimeImmutable $expiresAt = null,
    ): PlanningShareLinkInterface {
        $link = $this->links->create($calendars, 'Test', $mode, $expiresAt);
        $this->created[] = [PlanningShareLink::class, (int) $link->getId()];

        return $link;
    }

    private function event(Planning $planning, string $title): void
    {
        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle($title);
        $event->setSpan(new DateTimeImmutable('2026-09-01 10:00'), new DateTimeImmutable('2026-09-01 11:00'));
        $this->entityManager->persist($event);
        $this->entityManager->flush();
        $this->created[] = [PlanningEvent::class, (int) $event->getId()];
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

    /** A calendar with a live feed link, which is what most of these need. */
    private function published(): PlanningShareLinkInterface
    {
        return $this->link([$this->calendar()]);
    }
}
