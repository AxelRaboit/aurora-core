<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Live\Service\NotePresence;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_column;
use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;

/**
 * Who is on a note, and what the page is told about it.
 *
 * **The test environment has no `MERCURE_URL`**, which is the state most
 * installations will be in. So these tests are also the guarantee that the
 * room works without a hub: the page is handed no address to connect to -
 * which is what tells it to keep asking - and every answer still carries the
 * room and the version.
 */
final class NoteLiveRoomTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    private User $teammate;

    private User $outsider;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $this->owner = $this->user('proprietaire');
        $this->teammate = $this->user('collegue');
        $this->outsider = $this->user('dehors');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach ($this->entityManager->getRepository(NoteSpace::class)->findBy(['owner' => $this->users, 'personalUser' => null]) as $space) {
            $this->entityManager->remove($space);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        foreach ($this->users as $id) {
            $user = $this->entityManager->find(User::class, $id);
            if (null !== $user) {
                $this->entityManager->remove($user);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    /**
     * The guarantee that makes the hub optional.
     *
     * No hub in the test environment, so `streamUrl` is null - which is what
     * tells the page to keep asking - and the beat still answers with
     * everything it needs.
     */
    public function testTheRoomWorksWithNoHubConfigured(): void
    {
        $note = $this->sharedNote();

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->beat($note);

        self::assertResponseIsSuccessful();
        self::assertNull($body['streamUrl']);
        self::assertSame(NotePresence::BEAT_SECONDS, $body['beatSeconds']);
        self::assertSame($note->getVersion(), $body['version']);
        self::assertSame($this->owner->getId(), $body['selfUserId']);
    }

    /** Two people on the note, and each one sees the other and not themselves. */
    public function testEachPersonSeesTheOthersAndNeverThemselves(): void
    {
        $note = $this->sharedNote();

        $this->client->loginUser($this->owner, 'admin');
        $alone = $this->beat($note);
        self::assertSame([], $alone['people']);

        $this->client->loginUser($this->teammate, 'admin');
        $second = $this->beat($note);
        self::assertSame([$this->owner->getId()], array_column($second['people'], 'userId'));

        $this->client->loginUser($this->owner, 'admin');
        $first = $this->beat($note);
        self::assertSame([$this->teammate->getId()], array_column($first['people'], 'userId'));
    }

    /** The room says whether somebody is writing or only reading. */
    public function testTheRoomSaysWhoIsWritingAndWhoIsReading(): void
    {
        $note = $this->sharedNote();

        $this->client->loginUser($this->teammate, 'admin');
        $this->beat($note, editing: false);

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->beat($note, editing: true);

        self::assertSame([['userId' => $this->teammate->getId(), 'name' => 'collegue', 'editing' => false]], $body['people']);
    }

    /**
     * Reading the note is what lets somebody into the room - and not reading
     * it is what keeps them out.
     */
    public function testSomebodyWhoCannotReadTheNoteIsNotInItsRoom(): void
    {
        $note = $this->note('Journal', $this->personalSpaceOf($this->owner));

        $this->client->loginUser($this->outsider, 'admin');
        $this->beat($note);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Presence is forgotten on its own.
     *
     * There is no "leaving" call and there cannot be one: a closed laptop
     * sends nothing. What makes the room right is that an entry nobody
     * refreshes falls out of it.
     */
    public function testAnEntryNobodyRefreshesFallsOutOfTheRoom(): void
    {
        $presence = static::getContainer()->get(NotePresence::class);
        self::assertInstanceOf(NotePresence::class, $presence);

        self::assertGreaterThan(NotePresence::BEAT_SECONDS, NotePresence::STALE_AFTER_SECONDS);

        $note = $this->sharedNote();
        $owner = $this->entityManager->find(User::class, $this->owner->getId());
        $teammate = $this->entityManager->find(User::class, $this->teammate->getId());
        self::assertNotNull($owner);
        self::assertNotNull($teammate);

        $presence->beat($note, $owner, true);
        self::assertSame([$owner->getId()], array_column($presence->on($note), 'userId'));

        // Somebody else arrives, and both are in the room.
        $presence->beat($note, $teammate, false);
        self::assertCount(2, $presence->on($note));

        // Asked from the first person's point of view, the room is the other.
        self::assertSame([$teammate->getId()], array_column($presence->on($note, $owner), 'userId'));
    }

    /**
     * @return array<string, mixed>
     */
    private function beat(MarkdownNote $note, bool $editing = true): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_live_beat', ['id' => $note->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['editing' => $editing], JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** A note in a space both the owner and the teammate read. */
    private function sharedNote(): MarkdownNote
    {
        $space = new NoteSpace();
        $space->setOwner($this->managed($this->owner))
            ->setName('Équipe')
            ->setAccess(NoteSpaceAccessEnum::Backoffice)
            ->setDefaultRole(NoteSpaceRoleEnum::Editor);
        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $this->note('Procédure', $space);
    }

    private function note(string $title, NoteSpaceInterface $space): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->managed($this->owner))
            ->setSpace($this->managed($space))
            ->setTitle($title)
            ->setContent('Le texte');
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setEmail(sprintf('salon-%s-%s@aurora.test', $name, bin2hex(random_bytes(4))))
            ->setName($name)
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['notes.markdown.use'])
            ->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }

    private function managed(object $entity): object
    {
        /** @var object $reference */
        $reference = $this->entityManager->getReference($entity::class, $entity->getId());

        return $reference;
    }
}
