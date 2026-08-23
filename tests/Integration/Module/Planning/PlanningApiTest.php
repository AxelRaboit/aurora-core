<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
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
     */
    public function testAnAllDayEventIsSnappedToItsDay(): void
    {
        $planning = $this->calendar();

        $body = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Congé',
            'startAt' => '2026-08-23T14:32:00',
            'endAt' => '2026-08-23T14:32:00',
            'allDay' => true,
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('T00:00:00', $body['event']['startAt']);
        self::assertStringContainsString('T23:59:59', $body['event']['endAt']);
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
}
