<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMember;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_column;
use function array_map;
use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;

/**
 * A note handed to one person, who is nowhere near its space.
 *
 * The whole point of the feature is what it does **not** open: the guest gets
 * one page's text, and the notebook around it stays as invisible as it was.
 * Most of what follows therefore asserts a 404.
 *
 * Accounts without an administrator role, on purpose: an administrator has
 * every right in Aurora, and a test run with one would prove nothing.
 */
final class NoteSharedWithPeopleTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    /** Writes the notes, in their own personal space. */
    private User $owner;

    /** Gets one note, and nothing else. */
    private User $guest;

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
        $this->guest = $this->user('invite');
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

    /** The one note arrives; the one beside it does not. */
    public function testAHandedNoteOpensAndItsNeighbourStaysInvisible(): void
    {
        $space = $this->personalSpaceOf($this->owner);
        $shared = $this->note('Compte rendu', $space);
        $other = $this->note('Salaires', $space);

        $this->client->loginUser($this->guest, 'admin');
        self::assertNotContains($shared->getId(), $this->listedIds());

        $this->grant($shared, NoteMemberRoleEnum::Reader);

        self::assertContains($shared->getId(), $this->listedIds());
        self::assertNotContains($other->getId(), $this->listedIds());

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $shared->getId()]));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $other->getId()]));
        self::assertResponseStatusCodeSame(404);
    }

    /** A reader reads. Saving is refused like it is for anybody else. */
    public function testAReaderGrantDoesNotWrite(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner), 'Le texte d\'origine');
        $this->grant($note, NoteMemberRoleEnum::Reader);

        $this->client->loginUser($this->guest, 'admin');
        $this->post('suite_notes_markdown_update', ['title' => 'Détournée', 'content' => 'x', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        // The editor page sends a reader to the reading page, as it does for
        // somebody who reads a space without writing it.
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $note->getId()]));
        self::assertResponseRedirects($this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));

        $this->entityManager->clear();
        self::assertSame('Le texte d\'origine', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** An editor writes the text, and the editor page opens for them. */
    public function testAnEditorGrantWritesTheText(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner), 'Le texte d\'origine');
        $this->grant($note, NoteMemberRoleEnum::Editor);

        $this->client->loginUser($this->guest, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();

        $this->post('suite_notes_markdown_update', ['title' => 'Procédure', 'content' => 'Relue', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('Relue', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /**
     * What an editor grant is **not**: a say in what becomes of the note.
     *
     * Each of these acts on the notebook rather than on the page - and the
     * move is the one that matters most, since a guest who could move the
     * note into their own space would simply have taken it.
     */
    public function testAnEditorGrantDecidesNothingAboutTheNote(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner));
        $this->grant($note, NoteMemberRoleEnum::Editor);
        $guestSpace = $this->personalSpaceOf($this->guest);

        $this->client->loginUser($this->guest, 'admin');

        $this->post('suite_notes_markdown_move', ['spaceId' => $guestSpace->getId()], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->post('suite_notes_markdown_delete', [], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->post('suite_notes_markdown_force_delete', [], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->post('suite_notes_markdown_duplicate', [], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->post('suite_notes_markdown_template', ['template' => true], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertFalse($reloaded?->isTrashed());
        self::assertSame($this->personalSpaceOf($this->owner)->getId(), $reloaded?->getSpace()->getId());
    }

    /** A share does not spread: the guest cannot hand the note on. */
    public function testAGuestCannotShareTheNoteFurther(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner));
        $this->grant($note, NoteMemberRoleEnum::Editor);
        $third = $this->user('tiers');

        $this->client->loginUser($this->guest, 'admin');

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_people_list', ['noteId' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);

        $this->post('suite_notes_markdown_people_set', ['userId' => $third->getId(), 'role' => 'editor'], ['noteId' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        // Nor to anybody holding an address: publishing is not writing.
        $this->post('suite_notes_markdown_shares_create', ['noteId' => $note->getId(), 'label' => 'fuite']);
        self::assertResponseStatusCodeSame(422);
    }

    /** Taking it back closes it, in the same breath. */
    public function testTakingTheNoteBackClosesIt(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner));
        $this->grant($note, NoteMemberRoleEnum::Editor);

        $this->client->loginUser($this->guest, 'admin');
        self::assertContains($note->getId(), $this->listedIds());

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_people_remove', [], ['noteId' => $note->getId(), 'userId' => $this->guest->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->guest, 'admin');
        self::assertNotContains($note->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);
    }

    /** The owner's screen says who holds the note, and who else could. */
    public function testTheOwnerSeesWhoTheNoteWasHandedTo(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner));

        $this->client->loginUser($this->owner, 'admin');
        $created = $this->post(
            'suite_notes_markdown_people_set',
            ['userId' => $this->guest->getId(), 'role' => 'editor'],
            ['noteId' => $note->getId()],
        );
        self::assertResponseIsSuccessful();
        self::assertSame('editor', $created['member']['role'] ?? null);

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_people_list', ['noteId' => $note->getId()]));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([$this->guest->getId()], array_column($body['members'], 'userId'));
        self::assertContains($this->guest->getId(), array_column($body['people'], 'id'));
        // Never yourself: you already have the note.
        self::assertNotContains($this->owner->getId(), array_column($body['people'], 'id'));
    }

    /** A second call changes the role instead of adding a second row. */
    public function testHandingTheSameNoteTwiceChangesTheRole(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner));

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_people_set', ['userId' => $this->guest->getId(), 'role' => 'editor'], ['noteId' => $note->getId()]);
        $this->post('suite_notes_markdown_people_set', ['userId' => $this->guest->getId(), 'role' => 'reader'], ['noteId' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(MarkdownNoteMember::class)->findBy(['note' => $note->getId()]);
        self::assertCount(1, $rows);
        self::assertSame(NoteMemberRoleEnum::Reader, $rows[0]->getRole());
    }

    /**
     * A bulk tag rename over "my notes" does not reach into a page somebody
     * shared with me: that tag is theirs, on their note.
     */
    public function testABulkTagRenameDoesNotReachAHandedNote(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner));
        $note->setTags(['projet']);
        $this->entityManager->flush();
        $this->grant($note, NoteMemberRoleEnum::Editor);

        $this->client->loginUser($this->guest, 'admin');
        $this->post('suite_notes_markdown_tags_rename', ['from' => 'projet', 'to' => 'chantier']);

        $this->entityManager->clear();
        self::assertSame(['projet'], $this->entityManager->find(MarkdownNote::class, $note->getId())?->getTags());
    }

    /**
     * The guest's trash stays their own.
     *
     * Emptying a trash erases for good, and a row of a space they cannot see
     * has no business being listed among their own deletions - it would show
     * them that the space keeps something from them.
     */
    public function testAHandedNoteNeverEntersTheGuestsTrash(): void
    {
        $note = $this->note('Procédure', $this->personalSpaceOf($this->owner));
        $this->grant($note, NoteMemberRoleEnum::Editor);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_delete', [], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $noteRepository = static::getContainer()->get(MarkdownNoteRepository::class);
        self::assertInstanceOf(MarkdownNoteRepository::class, $noteRepository);

        $guest = $this->entityManager->find(User::class, $this->guest->getId());
        self::assertNotNull($guest);
        self::assertSame([], array_map(
            static fn (MarkdownNote $trashed): ?int => $trashed->getId(),
            $noteRepository->findTrashedRootsForUser($guest),
        ));
    }

    private function grant(MarkdownNote $note, NoteMemberRoleEnum $role): void
    {
        $member = new MarkdownNoteMember();
        $member->setNote($this->managed($note))
            ->setUser($this->managed($this->guest))
            ->setRole($role);
        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    private function note(string $title, NoteSpaceInterface $space, string $content = ''): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->managed($this->owner))
            ->setSpace($this->managed($space))
            ->setTitle($title)
            ->setContent($content);
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setEmail(sprintf('partage-%s-%s@aurora.test', $name, bin2hex(random_bytes(4))))
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

    /** @return list<int> */
    private function listedIds(): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_list'));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): int => (int) $row['id'], $body['notes']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function post(string $route, array $payload = [], array $parameters = []): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate($route, $parameters),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function managed(object $entity): object
    {
        /** @var object $reference */
        $reference = $this->entityManager->getReference($entity::class, $entity->getId());

        return $reference;
    }
}
