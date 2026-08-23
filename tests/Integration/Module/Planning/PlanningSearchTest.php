<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Planning\Search\PlanningBackendSearchProvider;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The calendar in the global search.
 *
 * The scoping is the part with a consequence: global search reaches every screen,
 * so an unscoped title match is the shortest path there is to reading somebody
 * else's private calendar.
 */
final class PlanningSearchTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private PlanningBackendSearchProvider $provider;

    private User $admin;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->provider = static::getContainer()->get(PlanningBackendSearchProvider::class);

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

    public function testItFindsAnEventAndAReminderByTitle(): void
    {
        $this->seed();
        $this->client->loginUser($this->admin, 'admin');

        $results = $this->provider->search('licorne');

        self::assertSame(['Réunion licorne'], array_column($results['events'], 'title'));
        self::assertSame(['Nourrir la licorne'], array_column($results['reminders'], 'title'));
    }

    /**
     * Each row brings the path that opens it, on the day it falls.
     *
     * The alternative was a branch in core's search that knows this module's route
     * names, which is what the provider registry exists to avoid.
     */
    public function testEachRowCarriesThePathThatOpensIt(): void
    {
        $this->seed();
        $this->client->loginUser($this->admin, 'admin');

        $results = $this->provider->search('licorne');

        self::assertStringContainsString('view=day', $results['events'][0]['path']);
        self::assertStringContainsString('date=2026-09-10', $results['events'][0]['path']);
    }

    /**
     * A calendar nobody can see is not searchable.
     *
     * The whole reason this provider reads the signed-in user.
     */
    public function testItNeverReturnsSomethingFromAnUnreachableCalendar(): void
    {
        $hidden = new Planning();
        $hidden->setName('Invisible');
        $this->entityManager->persist($hidden);

        $secret = new PlanningEvent();
        $secret->setPlanning($hidden);
        $secret->setTitle('Réunion licorne secrète');
        $secret->setSpan(new DateTimeImmutable('2026-09-10 10:00'), new DateTimeImmutable('2026-09-10 11:00'));
        $this->entityManager->persist($secret);
        $this->entityManager->flush();

        $this->created[] = [PlanningEvent::class, (int) $secret->getId()];
        $this->created[] = [Planning::class, (int) $hidden->getId()];

        $this->client->loginUser($this->admin, 'admin');

        $titles = array_column($this->provider->search('licorne')['events'] ?? [], 'title');
        self::assertNotContains('Réunion licorne secrète', $titles);
    }

    public function testWithNobodySignedInItReturnsNothing(): void
    {
        $this->seed();

        self::assertSame([], $this->provider->search('licorne'));
    }

    /**
     * A done reminder is still findable, unlike on the dashboard.
     *
     * Searching is how you find out whether you already did something.
     */
    public function testADoneReminderIsStillFound(): void
    {
        $planning = $this->calendar();

        $done = new PlanningReminder();
        $done->setPlanning($planning);
        $done->setTitle('Licorne déjà nourrie');
        $done->setDueAt(new DateTimeImmutable('2026-09-10 09:00'));
        $done->complete(new DateTimeImmutable('2026-09-10 08:00'));
        $this->entityManager->persist($done);
        $this->entityManager->flush();
        $this->created[] = [PlanningReminder::class, (int) $done->getId()];

        $this->client->loginUser($this->admin, 'admin');

        $rows = $this->provider->search('licorne')['reminders'];
        self::assertSame(['Licorne déjà nourrie'], array_column($rows, 'title'));
        self::assertTrue($rows[0]['completed']);
    }

    public function testAnEmptyQueryMatchesNothing(): void
    {
        $this->seed();
        $this->client->loginUser($this->admin, 'admin');

        $results = $this->provider->search('   ');

        self::assertSame([], $results['events']);
        self::assertSame([], $results['reminders']);
    }

    private function calendar(): Planning
    {
        $planning = new Planning();
        $planning->setName('Recherche');
        $planning->setOwner($this->admin);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $planning->getId()];

        return $planning;
    }

    private function seed(): void
    {
        $planning = $this->calendar();

        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle('Réunion licorne');
        $event->setSpan(new DateTimeImmutable('2026-09-10 10:00'), new DateTimeImmutable('2026-09-10 11:00'));
        $this->entityManager->persist($event);

        $reminder = new PlanningReminder();
        $reminder->setPlanning($planning);
        $reminder->setTitle('Nourrir la licorne');
        $reminder->setDueAt(new DateTimeImmutable('2026-09-11 09:00'));
        $this->entityManager->persist($reminder);

        $this->entityManager->flush();

        $this->created[] = [PlanningEvent::class, (int) $event->getId()];
        $this->created[] = [PlanningReminder::class, (int) $reminder->getId()];
    }
}
