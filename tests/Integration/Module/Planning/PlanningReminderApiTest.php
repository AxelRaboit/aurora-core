<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Planning\Reminder\Repository\PlanningReminderRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reminders through the API, alongside events.
 *
 * The second kind of thing on a calendar: one moment and a state, rather than two
 * ends. What is worth testing here is the state and the shared window, because the
 * date handling is the events' rules reused and already covered.
 */
final class PlanningReminderApiTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private PlanningReminderRepository $reminders;

    private User $admin;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
        $this->reminders = static::getContainer()->get(PlanningReminderRepository::class);

        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
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

    public function testAReminderIsCreatedOnACalendarTheReaderCanWriteTo(): void
    {
        $planning = $this->calendar();

        $body = $this->post('backend_planning_reminders_create', [
            'planningId' => $planning->getId(),
            'title' => 'Appeler le client',
            'dueAt' => '2026-09-01T10:00:00+02:00',
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame('reminder', $body['reminder']['kind']);
        self::assertSame('Appeler le client', $body['reminder']['title']);
        self::assertFalse($body['reminder']['completed']);

        // The instant, not the wall clock the browser happened to be on.
        self::assertSame(
            '2026-09-01 10:00',
            (new DateTimeImmutable((string) $body['reminder']['dueAt']))
                ->setTimezone(new DateTimeZone('Europe/Paris'))
                ->format('Y-m-d H:i'),
        );

        $this->created[] = [PlanningReminder::class, (int) $body['reminder']['id']];
    }

    public function testAReminderNeedsATitleAndADueDate(): void
    {
        $planning = $this->calendar();

        $body = $this->post('backend_planning_reminders_create', ['planningId' => $planning->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('title', $body['errors'] ?? []);
        self::assertArrayHasKey('dueAt', $body['errors'] ?? []);
    }

    public function testTickingOneOffAndBackAgain(): void
    {
        $reminder = $this->reminder('2026-09-01T10:00:00+02:00');

        $body = $this->post('backend_planning_reminders_toggle', [], ['id' => $reminder->getId()]);
        self::assertResponseIsSuccessful();
        self::assertTrue($body['reminder']['completed']);
        self::assertNotNull($body['reminder']['completedAt']);

        $body = $this->post('backend_planning_reminders_toggle', [], ['id' => $reminder->getId()]);
        self::assertResponseIsSuccessful();
        self::assertFalse($body['reminder']['completed']);
        self::assertNull($body['reminder']['completedAt']);
    }

    /**
     * Late is computed on the server.
     *
     * A browser with a wrong clock would otherwise strike through things that are
     * not late, or fail to strike through things that are. Both reminders sit
     * inside the window asked for, on either side of today - which is what this
     * test got wrong first, by putting the late one in 2020 and then looking for
     * it in a window starting in August 2026.
     */
    public function testLatenessComesFromTheServer(): void
    {
        $late = $this->reminder('2026-08-10T09:00:00+02:00');
        $soon = $this->reminder('2026-09-30T09:00:00+02:00');

        $rows = [];
        foreach ($this->window()['reminders'] as $row) {
            $rows[(int) $row['id']] = $row;
        }

        self::assertArrayHasKey($late->getId(), $rows);
        self::assertArrayHasKey($soon->getId(), $rows);
        self::assertTrue($rows[$late->getId()]['overdue']);
        self::assertFalse($rows[$soon->getId()]['overdue']);
    }

    /**
     * Ticking something off stops it being late.
     *
     * Late means "still to do and its moment has passed", so a done reminder is
     * never late however long ago it was due - otherwise a finished list stays
     * red for ever.
     */
    public function testSomethingDoneIsNotLate(): void
    {
        $late = $this->reminder('2026-08-10T09:00:00+02:00');

        $body = $this->post('backend_planning_reminders_toggle', [], ['id' => $late->getId()]);

        self::assertResponseIsSuccessful();
        self::assertTrue($body['reminder']['completed']);
        self::assertFalse($body['reminder']['overdue']);
    }

    /**
     * One window, both kinds.
     *
     * The grid draws them together, so two endpoints would be two round trips
     * whose results have to arrive together to be drawn at all.
     */
    public function testTheWindowReturnsEventsAndRemindersTogether(): void
    {
        $this->reminder('2026-09-01T10:00:00+02:00');

        $body = $this->window();

        self::assertArrayHasKey('events', $body);
        self::assertArrayHasKey('reminders', $body);
        self::assertNotEmpty($body['reminders']);
        foreach ($body['reminders'] as $row) {
            self::assertSame('reminder', $row['kind']);
        }
    }

    public function testAReminderOnACalendarNobodyCanSeeCannotBeReached(): void
    {
        // Ownerless and private: `findVisibleTo` returns what you own or what is
        // shared, so this belongs to nobody and is visible to nobody.
        $hidden = new Planning();
        $hidden->setName('Invisible');
        $this->entityManager->persist($hidden);

        $reminder = new PlanningReminder();
        $reminder->setPlanning($hidden);
        $reminder->setTitle('Caché');
        $reminder->setDueAt(new DateTimeImmutable('2026-09-01 10:00'));
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        $this->created[] = [PlanningReminder::class, (int) $reminder->getId()];
        $this->created[] = [Planning::class, (int) $hidden->getId()];

        $this->post('backend_planning_reminders_toggle', [], ['id' => $reminder->getId()]);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->refresh($reminder);
        self::assertFalse($reminder->isCompleted());
    }

    /** @return array<string, mixed> */
    private function window(): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('backend_planning_events', [
            'from' => '2026-08-01T00:00:00+00:00',
            'to' => '2026-10-05T00:00:00+00:00',
        ]));

        self::assertResponseIsSuccessful();

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function calendar(string $name = 'Rappels API'): Planning
    {
        $planning = new Planning();
        $planning->setName($name);
        $planning->setOwner($this->admin);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $planning->getId()];

        return $planning;
    }

    private function reminder(string $dueAt): PlanningReminder
    {
        $body = $this->post('backend_planning_reminders_create', [
            'planningId' => $this->calendar('Rappel '.$dueAt)->getId(),
            'title' => 'Appeler le client',
            'dueAt' => $dueAt,
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));

        $id = (int) $body['reminder']['id'];
        $this->created[] = [PlanningReminder::class, $id];

        $reminder = $this->entityManager->find(PlanningReminder::class, $id);
        self::assertInstanceOf(PlanningReminder::class, $reminder);

        return $reminder;
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
