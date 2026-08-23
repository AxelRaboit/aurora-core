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
 * The calendar's API, before there is anything to look at.
 *
 * Step 2 of the module exists to be testable this way: the routes, the privilege
 * gate and the two rules that are not a form's business - a calendar you cannot
 * see is not a calendar you can write into, and an event a module owns is not
 * yours to change.
 */
final class PlanningApiTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $admin;

    /**
     * What to clean up, as class and id rather than as objects.
     *
     * `merge()` is gone in ORM 3, so a detached entity cannot be reattached; and
     * an object removed by a cascade during the test is one this would try to
     * remove twice. Looking each one up by id answers both: gone is gone.
     *
     * @var list<array{class-string, int}>
     */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $users = static::getContainer()->get(UserRepository::class);
        $admin = $users->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
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
     * Owned by the signed-in user, which is the only way a private calendar is
     * reachable - and the only way the manager ever creates one. An ownerless
     * private calendar belongs to nobody and `findVisibleTo` is right to leave it
     * out; that is what this helper got wrong first.
     */
    private function calendar(string $name = 'Travail'): Planning
    {
        $planning = new Planning();
        $planning->setName($name);
        $planning->setOwner($this->admin);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $planning->getId()];

        return $planning;
    }

    /** @return array<string, mixed> */
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

    public function testACalendarIsCreatedWithAColourFromThePalette(): void
    {
        $body = $this->post('backend_planning_calendars_create', [
            'name' => 'Photo',
            'colourSlot' => 2,
            'visibility' => 'shared',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Photo', $body['calendar']['name']);
        self::assertSame(2, $body['calendar']['colourSlot']);

        $this->created[] = [Planning::class, (int) $body['calendar']['id']];
    }

    /**
     * A slot outside the palette is refused rather than clamped at this level:
     * the entity clamps so a fixture cannot break a grid, and the form says so
     * because somebody chose it.
     */
    public function testAColourOutsideThePaletteIsRefused(): void
    {
        $body = $this->post('backend_planning_calendars_create', ['name' => 'Trop', 'colourSlot' => 99]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('colourSlot', $body['errors'] ?? []);
    }

    public function testAnEventCarriesItsAlertsBothWays(): void
    {
        $planning = $this->calendar('Rappels');

        $created = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Point hebdo',
            'startAt' => '2026-09-01T10:00',
            'endAt' => '2026-09-01T11:00',
            // Sent out of order and with a duplicate, the way a form that lets
            // you toggle chips actually produces them.
            'alerts' => [60, 10, 60],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([10, 60], $created['event']['alerts']);

        $this->created[] = [PlanningEvent::class, (int) $created['event']['id']];
    }

    /**
     * An offset the picker does not offer is dropped, not refused.
     *
     * Unlike a missing date, which fails the save and names the field: there is
     * no control that can produce 7 minutes, so it means a hand-written request,
     * and failing an otherwise valid event over it would be the wrong trade.
     */
    public function testAnUnknownOffsetIsIgnoredWithoutFailingTheSave(): void
    {
        $planning = $this->calendar('Offsets');

        $created = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Sept minutes',
            'startAt' => '2026-09-01T10:00',
            'endAt' => '2026-09-01T11:00',
            'alerts' => [7, 15],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([15], $created['event']['alerts']);

        $this->created[] = [PlanningEvent::class, (int) $created['event']['id']];
    }

    /**
     * Editing an event keeps the alerts it still has.
     *
     * The manager diffs rather than clearing and re-adding, and this is what that
     * buys: a alert that survives an edit keeps its `sentAt`, so renaming an
     * event just after its alert fired does not fire it again.
     */
    public function testEditingAnEventKeepsASentAlertSent(): void
    {
        $planning = $this->calendar('Édition');

        $created = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Avant',
            'startAt' => '2026-09-01T10:00',
            'endAt' => '2026-09-01T11:00',
            'alerts' => [15, 60],
        ]);
        self::assertResponseIsSuccessful();

        $eventId = (int) $created['event']['id'];
        $this->created[] = [PlanningEvent::class, $eventId];

        $event = $this->entityManager->find(PlanningEvent::class, $eventId);
        self::assertInstanceOf(PlanningEvent::class, $event);

        $kept = null;
        foreach ($event->getAlerts() as $alert) {
            if (15 === $alert->getMinutesBefore()) {
                $alert->markSent(new DateTimeImmutable('2026-09-01T09:45'));
                $kept = $alert->getId();
            }
        }
        $this->entityManager->flush();
        self::assertNotNull($kept);

        $updated = $this->post(
            'backend_planning_events_update',
            [
                'planningId' => $planning->getId(),
                'title' => 'Après',
                'startAt' => '2026-09-01T10:00',
                'endAt' => '2026-09-01T11:00',
                'alerts' => [15],
            ],
            ['id' => $eventId],
        );

        self::assertResponseIsSuccessful();
        self::assertSame([15], $updated['event']['alerts']);

        $this->entityManager->clear();
        $event = $this->entityManager->find(PlanningEvent::class, $eventId);
        self::assertInstanceOf(PlanningEvent::class, $event);
        self::assertCount(1, $event->getAlerts());

        $survivor = $event->getAlerts()->first();
        self::assertNotFalse($survivor);
        self::assertSame($kept, $survivor->getId());
        self::assertNotNull($survivor->getSentAt());
    }

    public function testAnEventNeedsBothEndsAndTheRightOrder(): void
    {
        $planning = $this->calendar();

        $missing = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Sans date',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('startAt', $missing['errors'] ?? []);

        $backwards = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'À reculons',
            'startAt' => '2026-08-23T15:00:00',
            'endAt' => '2026-08-23T14:00:00',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('endAt', $backwards['errors'] ?? []);
    }

    /**
     * An all-day event owns whole days. Without the snap a day-long event created
     * at 14:00 runs to 14:00 the next day, and a month grid draws it across two
     * cells.
     *
     * The day is the calendar's day, not UTC's: the instant sent here is 16:32 in
     * Paris, so "the whole day" runs from midnight Paris - which is 22:00 UTC the
     * evening before. Asserting the UTC digits instead would pass with the snap
     * done in the wrong zone, and a reader in Paris would see a day off that
     * starts at 02:00 and spills into a second cell.
     */
    public function testAnAllDayEventIsSnappedToTheCalendarsWholeDay(): void
    {
        $planning = $this->calendar();
        self::assertSame('Europe/Paris', $planning->getTimezone());

        $body = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Congé',
            'startAt' => '2026-08-23T14:32:00+00:00',
            'endAt' => '2026-08-23T14:32:00+00:00',
            'allDay' => true,
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));

        $zone = new DateTimeZone('Europe/Paris');
        $start = (new DateTimeImmutable((string) $body['event']['startAt']))->setTimezone($zone);
        $end = (new DateTimeImmutable((string) $body['event']['endAt']))->setTimezone($zone);

        self::assertSame('2026-08-23 00:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-23 23:59:59', $end->format('Y-m-d H:i:s'));

        $this->created[] = [PlanningEvent::class, (int) $body['event']['id']];
    }

    /**
     * The defect this whole timezone pass exists for.
     *
     * The wire carries an instant and the column holds UTC, so what comes back
     * has to be the same moment that went in. Before this, a naked
     * `2026-09-01T10:00` was read as UTC, stored, and served back as
     * `10:00+00:00` - which a browser in Paris draws at 12:00. Typing a time and
     * reopening the event showed a different one.
     */
    public function testAnEventComesBackAtTheInstantItWasSentFor(): void
    {
        $planning = $this->calendar('Instants');

        $body = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Dix heures à Paris',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));

        $start = new DateTimeImmutable((string) $body['event']['startAt']);
        self::assertSame(
            (new DateTimeImmutable('2026-09-01T10:00:00+02:00'))->getTimestamp(),
            $start->getTimestamp(),
        );
        self::assertSame(
            '2026-09-01 10:00',
            $start->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'),
        );

        $this->created[] = [PlanningEvent::class, (int) $body['event']['id']];
    }

    /**
     * A alert is due at an instant, not at a wall clock.
     *
     * 30 minutes before 10:00 Paris is 09:30 Paris, whatever UTC calls it. Worth
     * its own case because `remindAt` is stored: the subtraction happens once, on
     * write, so getting the stored instant wrong means the alert fires at the
     * wrong moment for ever after.
     */
    public function testAAlertIsDueRelativeToTheRealInstant(): void
    {
        $planning = $this->calendar('Rappel instant');

        $body = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Dix heures moins le quart',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
            'alerts' => [30],
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));

        $eventId = (int) $body['event']['id'];
        $this->created[] = [PlanningEvent::class, $eventId];

        $this->entityManager->clear();
        $event = $this->entityManager->find(PlanningEvent::class, $eventId);
        self::assertInstanceOf(PlanningEvent::class, $event);

        $alert = $event->getAlerts()->first();
        self::assertNotFalse($alert);
        self::assertSame(
            '2026-09-01 09:30',
            $alert->getRemindAt()->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'),
        );
    }

    /**
     * An id in a payload is a claim. A reader who cannot see a calendar must not
     * be able to drop an event into it, and the answer is the same one a missing
     * calendar gets - saying "you cannot see that one" would confirm it exists.
     */
    public function testAnEventCannotBeDroppedIntoACalendarNobodyCanSee(): void
    {
        $body = $this->post('backend_planning_events_create', [
            'planningId' => 999_999,
            'title' => 'Ailleurs',
            'startAt' => '2026-08-23T14:00:00',
            'endAt' => '2026-08-23T15:00:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('planningId', $body['errors'] ?? []);
    }

    /**
     * An event a module pushed reflects a date that lives elsewhere. The screen
     * leaves out Edit and Delete; this is the same answer for a request that
     * arrives without one.
     */
    public function testAnEventOwnedByAModuleIsNotEditable(): void
    {
        $planning = $this->calendar();

        $event = new PlanningEvent();
        $event->setTitle('Publication programmée')
            ->setPlanning($planning)
            ->setSpan(new DateTimeImmutable('2026-08-23 09:00'), new DateTimeImmutable('2026-08-23 10:00'))
            ->setSource('editorial.post', 4321, 'Grille 48 colonnes');
        $this->entityManager->persist($event);
        $this->entityManager->flush();
        $this->created[] = [PlanningEvent::class, (int) $event->getId()];

        $updated = $this->post('backend_planning_events_update', [
            'planningId' => $planning->getId(),
            'title' => 'Renommée',
            'startAt' => '2026-08-23T09:00:00',
            'endAt' => '2026-08-23T10:00:00',
        ], ['id' => $event->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('event', $updated['errors'] ?? []);

        $this->post('backend_planning_events_delete', [], ['id' => $event->getId()]);
        self::assertResponseStatusCodeSame(422);

        // Still there, and still saying where it came from. Looked up again
        // rather than refreshed: the requests above detached it.
        $stored = $this->entityManager->find(PlanningEvent::class, $event->getId());
        self::assertInstanceOf(PlanningEvent::class, $stored);
        self::assertSame('Publication programmée', $stored->getTitle());
    }

    public function testTheWindowRefusesARangeThatIsNotOne(): void
    {
        $this->client->request('GET', $this->urlGenerator->generate('backend_planning_events', [
            'from' => '2026-09-01T00:00:00',
            'to' => '2026-08-01T00:00:00',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * The page renders, mounts the app, and hands it the calendars.
     *
     * A smoke test and not a UI one: what it proves is the wiring - the route,
     * the privilege, the template namespace `@Planning` resolving without a
     * bundle, and the payload reaching the mount point. Everything the grid does
     * with that payload is tested in `monthGrid.test.js`, without a browser.
     */
    public function testThePageMountsTheCalendarWithItsPayload(): void
    {
        $planning = $this->calendar('Travail');

        $crawler = $this->client->request('GET', $this->urlGenerator->generate('backend_planning_calendar'));

        self::assertResponseIsSuccessful();

        $mount = $crawler->filter('[data-vue-component], [data-v-component], div[data-props]');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('PlanningApp', $html);
        self::assertStringContainsString('Travail', $html);
        self::assertGreaterThan(0, $mount->count() + mb_substr_count($html, 'PlanningApp'));
        self::assertSame('Travail', $planning->getName());
    }
}
