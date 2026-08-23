<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Dashboard\PlanningStatsProvider;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The calendar's dashboard tile.
 *
 * What is worth testing here is the scoping, because that is the part with a
 * consequence: this provider reads the signed-in user where Editorial's
 * deliberately does not, and getting it wrong puts somebody else's private events
 * on your dashboard.
 */
final class PlanningDashboardTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private PlanningStatsProvider $provider;

    private User $admin;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->provider = static::getContainer()->get(PlanningStatsProvider::class);

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

    public function testItAnswersForTheModuleKeyTheDashboardGates(): void
    {
        self::assertSame('planning', $this->provider->getModuleKey());
    }

    /**
     * Nobody signed in means nothing to show, and the empty shape rather than
     * nothing at all - a panel handed a missing key draws worse than one handed a
     * zero.
     */
    public function testWithNobodySignedInItAnswersTheEmptyShape(): void
    {
        $stats = $this->provider->getStats()['planning'];

        self::assertSame(0, $stats['calendars']);
        self::assertSame(0, $stats['overdue']);
        self::assertSame([], $stats['upcoming']);
        self::assertArrayHasKey('path', $stats);
    }

    /**
     * A calendar nobody can see stays off the dashboard.
     *
     * The whole reason this provider is scoped. An ownerless private calendar is
     * visible to nobody, so its late reminder must not appear in anybody's count.
     */
    public function testAnUnreachableCalendarContributesNothing(): void
    {
        $hidden = new Planning();
        $hidden->setName('Invisible');
        $this->entityManager->persist($hidden);

        $late = new PlanningReminder();
        $late->setPlanning($hidden);
        $late->setTitle('Caché et en retard');
        $late->setDueAt(new DateTimeImmutable('-2 days'));
        $this->entityManager->persist($late);
        $this->entityManager->flush();

        $this->created[] = [PlanningReminder::class, (int) $late->getId()];
        $this->created[] = [Planning::class, (int) $hidden->getId()];

        $this->client->loginUser($this->admin, 'admin');
        $stats = $this->provider->getStats()['planning'];

        $titles = array_column($stats['upcoming'], 'title');
        self::assertNotContains('Caché et en retard', $titles);
    }

    /**
     * The reader's own calendar does contribute, and the late reminder is counted
     * rather than listed - the count is what makes somebody click.
     */
    public function testItCountsWhatIsLateAndListsWhatIsComing(): void
    {
        $mine = new Planning();
        $mine->setName('Le mien');
        $mine->setOwner($this->admin);
        $this->entityManager->persist($mine);

        $late = new PlanningReminder();
        $late->setPlanning($mine);
        $late->setTitle('En retard');
        $late->setDueAt(new DateTimeImmutable('-2 days'));
        $this->entityManager->persist($late);

        $soon = new PlanningReminder();
        $soon->setPlanning($mine);
        $soon->setTitle('Bientôt');
        $soon->setDueAt(new DateTimeImmutable('+2 days'));
        $this->entityManager->persist($soon);

        $done = new PlanningReminder();
        $done->setPlanning($mine);
        $done->setTitle('Déjà fait');
        $done->setDueAt(new DateTimeImmutable('+1 day'));
        $done->complete(new DateTimeImmutable());
        $this->entityManager->persist($done);

        $this->entityManager->flush();

        foreach ([$late, $soon, $done] as $reminder) {
            $this->created[] = [PlanningReminder::class, (int) $reminder->getId()];
        }
        $this->created[] = [Planning::class, (int) $mine->getId()];

        $this->client->loginUser($this->admin, 'admin');
        $stats = $this->provider->getStats()['planning'];

        self::assertGreaterThanOrEqual(1, $stats['overdue']);

        $titles = array_column($stats['upcoming'], 'title');
        self::assertContains('Bientôt', $titles);
        // Done, so not owed, so not coming up.
        self::assertNotContains('Déjà fait', $titles);
        // Late, so behind rather than ahead: it belongs to the count.
        self::assertNotContains('En retard', $titles);
    }
}
