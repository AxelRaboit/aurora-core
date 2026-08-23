<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Who is invited, and who may answer.
 *
 * The authority is the part with a consequence: answering an invitation is not
 * editing an event, so it is not gated the same way - and an attendee who could
 * answer one they were never sent would be able to add themselves to somebody
 * else's meeting.
 */
final class PlanningAttendeeTest extends IntegrationTestCase
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
        $this->planning->setName('Réunions');
        $this->planning->setOwner($admin);
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

    public function testInvitingSomebodyStartsThemWithoutAnAnswer(): void
    {
        $body = $this->createEvent([(int) $this->admin->getId()]);

        self::assertCount(1, $body['event']['attendees']);
        self::assertSame(
            PlanningAttendeeStatusEnum::NeedsAction->value,
            $body['event']['attendees'][0]['status'],
        );
        self::assertNull($body['event']['attendees'][0]['respondedAt']);
    }

    public function testAnswering(): void
    {
        $body = $this->createEvent([(int) $this->admin->getId()]);
        $id = (int) $body['event']['id'];

        $answered = $this->post('backend_planning_events_respond', ['status' => 'accepted'], ['id' => $id]);

        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $answered['event']['attendees'][0]['status']);
        self::assertNotNull($answered['event']['attendees'][0]['respondedAt']);
    }

    /**
     * Going back to no answer clears the date.
     *
     * That is not an answer, it is the absence of one - and a date beside it would
     * say somebody decided not to decide at a particular moment.
     */
    public function testUnansweringClearsTheDate(): void
    {
        $body = $this->createEvent([(int) $this->admin->getId()]);
        $id = (int) $body['event']['id'];

        $this->post('backend_planning_events_respond', ['status' => 'accepted'], ['id' => $id]);
        $back = $this->post('backend_planning_events_respond', ['status' => 'needs_action'], ['id' => $id]);

        self::assertResponseIsSuccessful();
        self::assertNull($back['event']['attendees'][0]['respondedAt']);
    }

    /**
     * Answering an invitation you were not sent is refused.
     *
     * Otherwise it is a way to add yourself to somebody else's meeting.
     */
    public function testAnswerinAnEventYouWereNotInvitedToIsRefused(): void
    {
        $body = $this->createEvent([]);
        $id = (int) $body['event']['id'];

        $refused = $this->post('backend_planning_events_respond', ['status' => 'accepted'], ['id' => $id]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('status', $refused['errors'] ?? []);
    }

    public function testAnAnswerThatDoesNotExistIsRefused(): void
    {
        $body = $this->createEvent([(int) $this->admin->getId()]);
        $id = (int) $body['event']['id'];

        $this->post('backend_planning_events_respond', ['status' => 'peut-être bien'], ['id' => $id]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * An attendee who survives an edit keeps their answer.
     *
     * The reason the manager diffs the list rather than clearing it: renaming an
     * event must not un-accept everybody who had already said yes.
     */
    public function testEditingTheEventKeepsTheAnswersOfWhoeverStaysInvited(): void
    {
        $body = $this->createEvent([(int) $this->admin->getId()]);
        $id = (int) $body['event']['id'];

        $this->post('backend_planning_events_respond', ['status' => 'accepted'], ['id' => $id]);

        $updated = $this->post('backend_planning_events_update', [
            'planningId' => $this->planning->getId(),
            'title' => 'Renommée',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
            'attendees' => [(int) $this->admin->getId()],
        ], ['id' => $id]);

        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $updated['event']['attendees'][0]['status']);
    }

    public function testUninvitingSomebodyRemovesThem(): void
    {
        $body = $this->createEvent([(int) $this->admin->getId()]);
        $id = (int) $body['event']['id'];

        $updated = $this->post('backend_planning_events_update', [
            'planningId' => $this->planning->getId(),
            'title' => 'Sans personne',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
            'attendees' => [],
        ], ['id' => $id]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $updated['event']['attendees']);
    }

    /**
     * An id naming nobody is dropped, not refused.
     *
     * It means a stale list or a hand-written request, and failing the save of an
     * otherwise valid event over it would be the wrong trade.
     */
    public function testAnIdThatNamesNobodyIsIgnored(): void
    {
        $body = $this->createEvent([(int) $this->admin->getId(), 999_999]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $body['event']['attendees']);
    }

    /** @return array<string, mixed> */
    private function createEvent(array $attendees): array
    {
        $body = $this->post('backend_planning_events_create', [
            'planningId' => $this->planning->getId(),
            'title' => 'Réunion',
            'startAt' => '2026-09-01T10:00:00+02:00',
            'endAt' => '2026-09-01T11:00:00+02:00',
            'attendees' => $attendees,
        ]);

        self::assertResponseIsSuccessful(json_encode($body, JSON_THROW_ON_ERROR));
        $this->created[] = [PlanningEvent::class, (int) $body['event']['id']];

        return $body;
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
