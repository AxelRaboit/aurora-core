<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Link\Entity\PlanningShareLink;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Link\Manager\PlanningShareLinkManagerInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The guest page, which is the second unauthenticated thing this module serves.
 *
 * So these tests are about what a stranger holding a URL can reach, not about how
 * the grid looks. Three properties carry the whole design: the link decides which
 * calendars are visible, every failure looks the same, and there is nothing here to
 * write to.
 */
final class PlanningSharePageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $admin;

    private PlanningShareLinkManagerInterface $links;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
        $this->links = static::getContainer()->get(PlanningShareLinkManagerInterface::class);

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

    public function testThePageOpensWithoutSigningIn(): void
    {
        $link = $this->link([$this->calendar('Pro')]);

        // No `loginUser`. The whole point is a reader with no account.
        $this->client->request('GET', $this->pageUrl($link));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Test');
    }

    /**
     * A share link must never be indexed.
     *
     * It is a secret handed to one person, and a crawler that found it in a
     * referrer would hand it to everybody.
     */
    public function testThePageAsksNotToBeIndexed(): void
    {
        $this->client->request('GET', $this->pageUrl($this->link([$this->calendar()])));

        $robots = $this->client->getCrawler()->filter('meta[name="robots"]')->attr('content');

        self::assertStringContainsString('noindex', (string) $robots);
        self::assertStringContainsString('nofollow', (string) $robots);
    }

    /**
     * @return iterable<string, array{callable(self): string}>
     */
    public static function closedLinks(): iterable
    {
        yield 'never existed' => [static fn (self $test): string => $test->urlFor(str_repeat('a', 64))];
        yield 'expired' => [static fn (self $test): string => $test->pageUrl(
            $test->link([$test->calendar()], PlanningShareLinkModeEnum::Web, new DateTimeImmutable('-1 hour')),
        )];
        yield 'revoked' => [static fn (self $test): string => $test->pageUrl($test->revokedLink())];
        yield 'minted as a feed' => [static fn (self $test): string => $test->pageUrl(
            $test->link([$test->calendar()], PlanningShareLinkModeEnum::Ics),
        )];
    }

    /**
     * Every way a link can fail looks identical.
     *
     * Deliberate, and the reason this is one test over four cases rather than four
     * tests: telling them apart would confirm which random strings had once been
     * real, and the reader's question is only whether they can still use it.
     *
     * @param callable(self): string $url
     */
    #[DataProvider('closedLinks')]
    public function testEveryClosedLinkAnswersTheSameWay(callable $url): void
    {
        $this->client->request('GET', $url($this));

        self::assertResponseStatusCodeSame(404);
        // A sentence rather than a browser error page: somebody was sent this link
        // in good faith and deserves to be told what to do next.
        self::assertSelectorTextContains('h1', 'indisponible');
    }

    /**
     * The link decides which calendars are readable, and the request cannot argue.
     *
     * The id list comes from the link, so asking for somebody else's calendar in the
     * query string reaches nothing. This is the one that would matter if it broke.
     */
    public function testAGuestCannotWidenTheirOwnView(): void
    {
        $shared = $this->calendar('Partagé');
        $private = $this->calendar('Privé');
        $link = $this->link([$shared]);

        $this->event($shared, 'Visible au lien');
        $this->event($private, 'Rien à voir ici');

        $this->entityManager->clear();

        $this->client->request('GET', $this->urlGenerator->generate('planning_share_events', [
            'token' => $link->getToken(),
            'from' => '2026-08-01T00:00:00Z',
            'to' => '2026-10-01T00:00:00Z',
            // Asking for the other calendar outright, which the backend endpoint
            // would have no reason to refuse and this one never reads.
            'calendarIds' => [$private->getId()],
        ]));

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Visible au lien', $body);
        self::assertStringNotContainsString('Rien à voir ici', $body);
    }

    /**
     * Everything the guest is handed says it cannot be edited.
     *
     * Not the guarantee - that is the absence of a write route - but it is what
     * stops a grid offering a drag that would go nowhere.
     */
    public function testEveryEventComesBackReadOnly(): void
    {
        $planning = $this->calendar();
        $link = $this->link([$planning]);
        $this->event($planning, 'Réunion');

        $this->entityManager->clear();

        $this->client->request('GET', $this->urlGenerator->generate('planning_share_events', [
            'token' => $link->getToken(),
            'from' => '2026-08-01T00:00:00Z',
            'to' => '2026-10-01T00:00:00Z',
        ]));

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNotSame([], $payload['events']);

        foreach ($payload['events'] as $event) {
            self::assertTrue($event['readOnly'], 'a guest must not be offered an editable event');
        }
    }

    /** The window still has to be a window. */
    public function testABackwardsWindowIsRefused(): void
    {
        $link = $this->link([$this->calendar()]);

        $this->client->request('GET', $this->urlGenerator->generate('planning_share_events', [
            'token' => $link->getToken(),
            'from' => '2026-10-01T00:00:00Z',
            'to' => '2026-08-01T00:00:00Z',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    /** The URL is a secret, so nothing in between may keep the answer. */
    public function testTheWindowIsNotCacheableByAnythingInBetween(): void
    {
        $link = $this->link([$this->calendar()]);

        $this->client->request('GET', $this->urlGenerator->generate('planning_share_events', [
            'token' => $link->getToken(),
            'from' => '2026-08-01T00:00:00Z',
            'to' => '2026-10-01T00:00:00Z',
        ]));

        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    public function urlFor(string $token): string
    {
        return $this->urlGenerator->generate('planning_share_show', ['token' => $token]);
    }

    public function pageUrl(PlanningShareLinkInterface $link): string
    {
        return $this->urlFor($link->getToken());
    }

    /**
     * @param list<PlanningInterface> $calendars
     */
    public function link(
        array $calendars,
        PlanningShareLinkModeEnum $mode = PlanningShareLinkModeEnum::Web,
        ?DateTimeImmutable $expiresAt = null,
    ): PlanningShareLinkInterface {
        $link = $this->links->create($calendars, 'Test', $mode, $expiresAt);
        $this->created[] = [PlanningShareLink::class, (int) $link->getId()];

        return $link;
    }

    public function revokedLink(): PlanningShareLinkInterface
    {
        $link = $this->link([$this->calendar()]);
        $this->links->revoke($link);

        return $link;
    }

    public function calendar(string $name = 'Partage'): Planning
    {
        $planning = new Planning();
        $planning->setName($name);
        $planning->setOwner($this->admin);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $planning->getId()];

        return $planning;
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
}
