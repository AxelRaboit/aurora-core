<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Planning\Share\Entity\PlanningShare;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sharing a calendar with named people.
 *
 * The tests are about authority, because that is the part with a consequence:
 * seeing a calendar and writing into it became different questions the moment a
 * calendar could be shared read-only, and the code used to answer them with one
 * lookup.
 */
final class PlanningShareTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    private User $guest;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $users = static::getContainer()->get(UserRepository::class);

        $owner = $users->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;

        // A second backend account, because everything here is about two people.
        $guest = $users->findOneBy(['type' => UserTypeEnum::Backend->value, 'email' => 'partage@aurora.test']);
        if (!$guest instanceof User) {
            $guest = new User();
            $guest->setEmail('partage@aurora.test');
            $guest->setName('Invité');
            $guest->setType(UserTypeEnum::Backend);
            $guest->setPassword('x');
            $guest->setRoles($owner->getRoles());
            $this->entityManager->persist($guest);
            $this->entityManager->flush();
            $this->created[] = [User::class, (int) $guest->getId()];
        }
        $this->guest = $guest;
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
     * A private calendar shared with nobody stays invisible.
     */
    public function testAPrivateCalendarIsNotVisibleToSomebodyElse(): void
    {
        $planning = $this->calendar();

        $this->client->loginUser($this->guest, 'admin');

        self::assertNotContains($planning->getId(), $this->visibleIds());
    }

    public function testSharingMakesItVisible(): void
    {
        $planning = $this->calendar();
        $this->share($planning, canWrite: false);

        $this->client->loginUser($this->guest, 'admin');

        self::assertContains($planning->getId(), $this->visibleIds());
    }

    /**
     * Read-only means read-only.
     *
     * The reason `writableCalendar` stopped being "is it in the visible list": a
     * calendar shared for looking at is not a calendar you can put things on.
     */
    public function testAReadOnlyShareCannotWrite(): void
    {
        $planning = $this->calendar();
        $this->share($planning, canWrite: false);

        $this->client->loginUser($this->guest, 'admin');

        $body = $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Pas permis',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('planningId', $body['errors'] ?? []);
    }

    public function testAShareWithWritingCanWrite(): void
    {
        $planning = $this->calendar();
        $this->share($planning, canWrite: true);

        $this->client->loginUser($this->guest, 'admin');

        $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Permis',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * A calendar shared broadly stays writable by everyone.
     *
     * Unchanged on purpose: the share table was added beside the visibility rather
     * than replacing it, and turning `shared` into read-only would have changed the
     * behaviour of every calendar already shared.
     */
    public function testABroadlySharedCalendarIsStillWritable(): void
    {
        $planning = $this->calendar();
        $planning->setVisibility(PlanningVisibilityEnum::Shared);
        $this->entityManager->flush();

        $this->client->loginUser($this->guest, 'admin');

        $this->post('backend_planning_events_create', [
            'planningId' => $planning->getId(),
            'title' => 'Permis aussi',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * Handing out keys is the owner's authority, not a guest's.
     *
     * Somebody granted write access can put things on a calendar; deciding who else
     * gets in is a different question, and treating it as one would let a guest
     * share it on.
     */
    public function testAGuestCannotChangeTheSharing(): void
    {
        $planning = $this->calendar();
        $this->share($planning, canWrite: true);

        $this->client->loginUser($this->guest, 'admin');

        $this->post(
            'backend_planning_calendars_shares',
            ['shares' => [['userId' => $this->guest->getId(), 'canWrite' => true]]],
            ['id' => $planning->getId()],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheOwnerSetsTheWholeListAtOnce(): void
    {
        $planning = $this->calendar();
        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post(
            'backend_planning_calendars_shares',
            ['shares' => [['userId' => $this->guest->getId(), 'canWrite' => true]]],
            ['id' => $planning->getId()],
        );
        self::assertResponseIsSuccessful();
        self::assertCount(1, $body['calendar']['shares']);
        self::assertTrue($body['calendar']['shares'][0]['canWrite']);

        // Sent again without them: the list is the whole truth, so they are out.
        $emptied = $this->post(
            'backend_planning_calendars_shares',
            ['shares' => []],
            ['id' => $planning->getId()],
        );
        self::assertResponseIsSuccessful();
        self::assertSame([], $emptied['calendar']['shares']);
    }

    /**
     * Changing somebody's level is an update, not a delete and an insert.
     *
     * The unique index would refuse that order anyway.
     */
    public function testChangingALevelKeepsTheSameShare(): void
    {
        $planning = $this->calendar();
        $this->client->loginUser($this->owner, 'admin');

        $this->post(
            'backend_planning_calendars_shares',
            ['shares' => [['userId' => $this->guest->getId(), 'canWrite' => false]]],
            ['id' => $planning->getId()],
        );

        $body = $this->post(
            'backend_planning_calendars_shares',
            ['shares' => [['userId' => $this->guest->getId(), 'canWrite' => true]]],
            ['id' => $planning->getId()],
        );

        self::assertResponseIsSuccessful();
        self::assertCount(1, $body['calendar']['shares']);
        self::assertTrue($body['calendar']['shares'][0]['canWrite']);
    }

    /**
     * The owner is never in their own sharing list.
     *
     * They already hold every right the table can grant, and a row saying so would
     * be a row somebody could delete.
     */
    public function testTheOwnerIsNeverListedAsAShare(): void
    {
        $planning = $this->calendar();
        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post(
            'backend_planning_calendars_shares',
            ['shares' => [['userId' => $this->owner->getId(), 'canWrite' => true]]],
            ['id' => $planning->getId()],
        );

        self::assertResponseIsSuccessful();
        self::assertSame([], $body['calendar']['shares']);
    }

    /** @return list<int> */
    private function visibleIds(): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('backend_planning_calendar'));
        self::assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler()->filter(
            '[data-symfony--ux-vue--vue-component-value="planning/backend/planning/PlanningApp"]',
        );
        $props = json_decode((string) $crawler->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): int => (int) $row['id'], $props['calendars']);
    }

    private function calendar(): Planning
    {
        $planning = new Planning();
        $planning->setName('Privé');
        $planning->setOwner($this->owner);
        $planning->setVisibility(PlanningVisibilityEnum::Private);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $planning->getId()];

        return $planning;
    }

    private function share(Planning $planning, bool $canWrite): void
    {
        $share = new PlanningShare();
        $share->setUser($this->guest);
        $share->setCanWrite($canWrite);
        $planning->addShare($share);
        $this->entityManager->persist($share);
        $this->entityManager->flush();
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
