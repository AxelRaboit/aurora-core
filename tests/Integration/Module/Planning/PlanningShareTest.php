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

    /**
     * The visibility column, all the way round.
     *
     * Nothing covered this: the sharing table arrived later and took the tests
     * with it, so `visibility` had a form field, a DTO, a serializer entry and an
     * `applyInput` line, and no proof they were wired to each other. A `tryFrom`
     * that fell through would have silently written `private` on every save, which
     * is exactly what a reader would report as "my calendar went back to private".
     */
    public function testACalendarKeepsTheVisibilityItWasCreatedWith(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post('backend_planning_calendars_create', [
            'name' => 'Partagé à tous',
            'colourSlot' => 1,
            'timezone' => 'Europe/Paris',
            'visibility' => 'shared',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('shared', $body['calendar']['visibility']);

        $planning = $this->entityManager->find(Planning::class, (int) $body['calendar']['id']);
        self::assertInstanceOf(Planning::class, $planning);
        $this->created[] = [Planning::class, (int) $planning->getId()];

        self::assertSame(PlanningVisibilityEnum::Shared, $planning->getVisibility());
    }

    /**
     * And an edit moves it in both directions.
     *
     * Both ways in one test on purpose: going to `private` alone would pass
     * against code that ignores the field entirely, since `private` is also what
     * the fallback produces.
     */
    public function testEditingMovesTheVisibilityBothWays(): void
    {
        $planning = $this->calendar();
        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post(
            'backend_planning_calendars_update',
            $this->payload($planning, 'shared'),
            ['id' => $planning->getId()],
        );

        self::assertResponseIsSuccessful();
        self::assertSame('shared', $body['calendar']['visibility']);

        $body = $this->post(
            'backend_planning_calendars_update',
            $this->payload($planning, 'private'),
            ['id' => $planning->getId()],
        );

        self::assertResponseIsSuccessful();
        self::assertSame('private', $body['calendar']['visibility']);

        // Re-fetched, not refreshed: each HTTP request above ran in its own
        // kernel and cleared the manager, so the local object is detached.
        $stored = $this->entityManager->find(Planning::class, (int) $planning->getId());
        self::assertInstanceOf(Planning::class, $stored);
        self::assertSame(PlanningVisibilityEnum::Private, $stored->getVisibility());
    }

    /**
     * A calendar set to `shared` is visible without anybody being named on it.
     *
     * This is the other arm of `findVisibleTo` - `p.visibility = :shared` - and it
     * had no test. `testSharingMakesItVisible` above goes through the share table,
     * so the two look alike and cover different code: dropping this arm from the
     * query would have failed nothing.
     */
    public function testASharedCalendarIsVisibleToEverybodyWithoutAShare(): void
    {
        $planning = $this->calendar();
        $planning->setVisibility(PlanningVisibilityEnum::Shared);
        $this->entityManager->flush();

        $this->client->loginUser($this->guest, 'admin');

        self::assertContains($planning->getId(), $this->visibleIds());
        self::assertSame([], $this->sharesOf($planning->getId()), 'visible through the column, not a share');
    }

    /**
     * The owner sees their own private calendar.
     *
     * Reads as trivial and is the one that would have caught a calendar
     * disappearing from its owner's sidebar - the `p.owner = :owner` arm carries
     * every private calendar anybody has, so a rewrite of that `OR` chain that
     * looks harmless empties the screen.
     */
    public function testTheOwnerStillSeesTheirOwnPrivateCalendar(): void
    {
        $planning = $this->calendar();

        $this->client->loginUser($this->owner, 'admin');

        self::assertContains($planning->getId(), $this->visibleIds());
    }

    /**
     * The full payload the modal sends, with one field swapped.
     *
     * Written out rather than patched onto an array, because a partial payload
     * would test the factory's defaults instead of the field under test.
     *
     * @return array<string, mixed>
     */
    private function payload(Planning $planning, string $visibility): array
    {
        return [
            'name' => $planning->getName(),
            'description' => $planning->getDescription(),
            'colourSlot' => $planning->getColourSlot(),
            'timezone' => $planning->getTimezone(),
            'visibility' => $visibility,
        ];
    }

    /**
     * The shares the screen was told about, for one calendar.
     *
     * @return list<array<string, mixed>>
     */
    private function sharesOf(?int $id): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('backend_planning_calendar'));
        self::assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler()->filter(
            '[data-symfony--ux-vue--vue-component-value="planning/backend/planning/PlanningApp"]',
        );
        $props = json_decode((string) $crawler->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($props['calendars'] as $row) {
            if ((int) $row['id'] === $id) {
                return $row['shares'];
            }
        }

        return [];
    }

    /**
     * Opening a share link needs to own the calendar, not merely to write to it.
     *
     * Every other write on that screen asks whether you may edit; this one asks
     * whose calendar it is. Somebody a calendar was shared with, **even for
     * writing**, has no business publishing it to the internet - and that
     * distinction only exists in the route, so it is only provable here.
     */
    public function testAGuestWithWriteAccessCannotOpenAShareLink(): void
    {
        $planning = $this->calendar();
        $this->share($planning, canWrite: true);

        $this->client->loginUser($this->guest, 'admin');

        $body = $this->post('backend_planning_links_create', [
            'calendarIds' => [$planning->getId()],
            'label' => 'Tentative',
            'mode' => 'web',
        ]);

        // Refused as "not yours" rather than as a bad request: the ownership check
        // turns an id you do not own into an invalid selection.
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('calendarIds', $body['errors'] ?? []);
    }

    /** The owner can, and gets an address back. */
    public function testTheOwnerOpensALinkAndGetsItsAddress(): void
    {
        $planning = $this->calendar();

        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post('backend_planning_links_create', [
            'calendarIds' => [$planning->getId()],
            'label' => 'Marie, studio',
            'mode' => 'web',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Marie, studio', $body['link']['label']);
        self::assertStringContainsString('/planning/share/', (string) $body['link']['url']);
    }

    /**
     * A selection containing one calendar you do not own fails whole.
     *
     * A partial link would quietly share less than was asked for, and the person
     * who made it would find out from their guest.
     */
    public function testASelectionWithSomebodyElsesCalendarIsRefusedWhole(): void
    {
        $mine = $this->calendar();

        $theirs = new Planning();
        $theirs->setName('Pas à moi');
        $theirs->setOwner($this->guest);
        $this->entityManager->persist($theirs);
        $this->entityManager->flush();
        $this->created[] = [Planning::class, (int) $theirs->getId()];

        $this->client->loginUser($this->owner, 'admin');

        $this->post('backend_planning_links_create', [
            'calendarIds' => [$mine->getId(), $theirs->getId()],
            'label' => 'Les deux',
            'mode' => 'web',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** A link with no name is refused: an unnamed token cannot be revoked with confidence. */
    public function testALinkNeedsAName(): void
    {
        $planning = $this->calendar();

        $this->client->loginUser($this->owner, 'admin');

        $this->post('backend_planning_links_create', [
            'calendarIds' => [$planning->getId()],
            'label' => '   ',
            'mode' => 'web',
        ]);

        self::assertResponseStatusCodeSame(422);
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
