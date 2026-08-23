<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Editing a series, through the API, at each of the three scopes.
 *
 * These are the tests that matter most in the whole module. Getting a scope wrong
 * is not a rendering bug - it silently changes appointments somebody else is
 * relying on, and the reader who finds out is the one who missed a meeting.
 */
final class PlanningRecurrenceTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $admin;

    private Planning $planning;

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
        $this->client->loginUser($admin, 'admin');

        $this->planning = new Planning();
        $this->planning->setName('Série');
        $this->planning->setOwner($this->admin);
        $this->planning->setTimezone('Europe/Paris');
        $this->entityManager->persist($this->planning);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $this->planning->getId()];
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

    public function testAWeeklySeriesAppearsOnceAWeek(): void
    {
        $this->series();

        $titles = $this->windowStarts();

        self::assertSame(
            ['2026-09-07 09:00', '2026-09-14 09:00', '2026-09-21 09:00', '2026-09-28 09:00'],
            $titles,
        );
    }

    /**
     * Editing one occurrence leaves the rest alone.
     *
     * The single most important assertion here: the reader moved one meeting, and
     * every other Monday has to be exactly where it was.
     */
    public function testEditingOneOccurrenceLeavesTheRestWhereTheyWere(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_update', [
            'planningId' => $this->planning->getId(),
            'title' => 'Point hebdo, déplacé',
            'startAt' => '2026-09-14T14:00:00+02:00',
            'endAt' => '2026-09-14T15:00:00+02:00',
            'scope' => 'this',
            'occurrenceAt' => '2026-09-14T09:00:00+02:00',
        ], ['id' => $id]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['2026-09-07 09:00', '2026-09-14 14:00', '2026-09-21 09:00', '2026-09-28 09:00'],
            $this->windowStarts(),
        );

        // And the moved one is the only thing carrying the new title.
        $rows = $this->window()['events'];
        $moved = array_values(array_filter($rows, static fn (array $row): bool => 'Point hebdo, déplacé' === $row['title']));
        self::assertCount(1, $moved);
    }

    /**
     * The occurrence appears once, not twice.
     *
     * The detached row is returned by the query that finds every other row, so the
     * series has to stop emitting the date it replaced - or the reader sees the
     * meeting both where it was and where it moved to.
     */
    public function testAnEditedOccurrenceIsNotDrawnTwice(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_update', [
            'planningId' => $this->planning->getId(),
            'title' => 'Déplacé',
            'startAt' => '2026-09-14T14:00:00+02:00',
            'endAt' => '2026-09-14T15:00:00+02:00',
            'scope' => 'this',
            'occurrenceAt' => '2026-09-14T09:00:00+02:00',
        ], ['id' => $id]);

        self::assertCount(4, $this->window()['events']);
    }

    /**
     * "This and following" splits the series and leaves the past alone.
     *
     * Moving a Monday meeting to 14:00 from the third week on must not rewrite the
     * two weeks it already happened at 09:00.
     */
    public function testFollowingSplitsTheSeriesAndLeavesThePastAlone(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_update', [
            'planningId' => $this->planning->getId(),
            'title' => 'Point hebdo',
            'startAt' => '2026-09-21T14:00:00+02:00',
            'endAt' => '2026-09-21T15:00:00+02:00',
            'scope' => 'following',
            'occurrenceAt' => '2026-09-21T09:00:00+02:00',
        ], ['id' => $id]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['2026-09-07 09:00', '2026-09-14 09:00', '2026-09-21 14:00', '2026-09-28 14:00'],
            $this->windowStarts(),
        );
    }

    public function testAllRewritesEveryOccurrence(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_update', [
            'planningId' => $this->planning->getId(),
            'title' => 'Point hebdo',
            'startAt' => '2026-09-07T14:00:00+02:00',
            'endAt' => '2026-09-07T15:00:00+02:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
            'scope' => 'all',
            'occurrenceAt' => '2026-09-07T09:00:00+02:00',
        ], ['id' => $id]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['2026-09-07 14:00', '2026-09-14 14:00', '2026-09-21 14:00', '2026-09-28 14:00'],
            $this->windowStarts(),
        );
    }

    public function testDeletingOneOccurrenceLeavesTheRest(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_delete', [
            'scope' => 'this',
            'occurrenceAt' => '2026-09-14T09:00:00+02:00',
        ], ['id' => $id]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['2026-09-07 09:00', '2026-09-21 09:00', '2026-09-28 09:00'],
            $this->windowStarts(),
        );
    }

    /**
     * Deleting "this and following" stops the series rather than removing it.
     *
     * The occurrences already past are what was agreed, and a delete that took
     * them with it would erase history nobody asked to erase.
     */
    public function testDeletingFollowingStopsTheSeriesAndKeepsThePast(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_delete', [
            'scope' => 'following',
            'occurrenceAt' => '2026-09-21T09:00:00+02:00',
        ], ['id' => $id]);
        self::assertResponseIsSuccessful();

        self::assertSame(['2026-09-07 09:00', '2026-09-14 09:00'], $this->windowStarts());
    }

    public function testDeletingTheWholeSeriesRemovesEverything(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_delete', ['scope' => 'all'], ['id' => $id]);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->windowStarts());
        // Removed, so no cleanup needed - and nothing left to point at it.
        $this->created = array_values(array_filter(
            $this->created,
            static fn (array $row): bool => PlanningEvent::class !== $row[0],
        ));
    }

    /**
     * A scope that names an occurrence has to say which one.
     *
     * Refused rather than guessed: guessing would write to the wrong date, and the
     * reader would find a different meeting changed.
     */
    public function testAScopedEditWithoutAnOccurrenceIsRefused(): void
    {
        $id = $this->series();

        $body = $this->post('backend_planning_events_update', [
            'planningId' => $this->planning->getId(),
            'title' => 'Point hebdo',
            'startAt' => '2026-09-14T14:00:00+02:00',
            'endAt' => '2026-09-14T15:00:00+02:00',
            'scope' => 'this',
        ], ['id' => $id]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('occurrenceAt', $body['errors'] ?? []);
    }

    /**
     * Dragging one occurrence is the same decision as editing it.
     *
     * Answering it two ways would be two chances to answer it wrong.
     */
    public function testDraggingOneOccurrenceMovesOnlyThatOne(): void
    {
        $id = $this->series();

        $this->post('backend_planning_events_move', [
            'startAt' => '2026-09-14T16:00:00+02:00',
            'endAt' => '2026-09-14T17:00:00+02:00',
            'scope' => 'this',
            'occurrenceAt' => '2026-09-14T09:00:00+02:00',
        ], ['id' => $id]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['2026-09-07 09:00', '2026-09-14 16:00', '2026-09-21 09:00', '2026-09-28 09:00'],
            $this->windowStarts(),
        );
    }

    /** A weekly series of four Mondays in September 2026. */
    private function series(): int
    {
        $body = $this->post('backend_planning_events_create', [
            'planningId' => $this->planning->getId(),
            'title' => 'Point hebdo',
            'startAt' => '2026-09-07T09:00:00+02:00',
            'endAt' => '2026-09-07T10:00:00+02:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));

        $id = (int) $body['event']['id'];
        $this->created[] = [PlanningEvent::class, $id];

        return $id;
    }

    /** @return list<string> every appearance in September, on the calendar's clock */
    private function windowStarts(): array
    {
        $zone = new DateTimeZone('Europe/Paris');

        $starts = array_map(
            static fn (array $row): string => (new DateTimeImmutable((string) $row['startAt']))
                ->setTimezone($zone)
                ->format('Y-m-d H:i'),
            $this->window()['events'],
        );

        sort($starts);

        return $starts;
    }

    /** @return array<string, mixed> */
    private function window(): array
    {
        $this->entityManager->clear();

        $this->client->request('GET', $this->urlGenerator->generate('backend_planning_events', [
            'from' => '2026-09-01T00:00:00+00:00',
            'to' => '2026-10-01T00:00:00+00:00',
        ]));
        self::assertResponseIsSuccessful();

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function post(string $route, array $payload = [], array $params = []): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate($route, $params),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
