<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMember;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Comments on a note and @mentions(09/10/2026): who may write them, who
 * may settle or remove them, and who is told.
 */
final class NoteCommentTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    private User $reader;

    private User $stranger;

    private MarkdownNote $note;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        $owner = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;

        $note = new MarkdownNote();
        $note->setUser($owner);
        $note->setSpace($this->personalSpaceOf($owner));
        $note->setTitle('Devis Verrier');
        $note->setContent('Le budget reste à valider.');

        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];
        $this->note = $note;

        $this->reader = $this->person('lecteur');
        $member = new MarkdownNoteMember();
        $member->setNote($note)->setUser($this->reader)->setRole(NoteMemberRoleEnum::Reader);
        $this->entityManager->persist($member);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNoteMember::class, (int) $member->getId()];

        $this->stranger = $this->person('inconnu');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Notification::class)->findBy(['type' => ['notes.comment', 'notes.mention']]) as $notification) {
            $this->entityManager->remove($notification);
        }

        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }

        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testAReaderCommentsAPassageAndTheAuthorIsTold(): void
    {
        $this->client->loginUser($this->reader, 'admin');
        $body = $this->post($this->commentsPath(), ['quote' => 'Le budget', 'body' => 'Quel montant ?']);
        self::assertResponseIsSuccessful();

        self::assertCount(1, $body['threads']);
        self::assertSame('Le budget', $body['threads'][0]['quote']);
        self::assertSame('lecteur', $body['threads'][0]['authorName']);
        self::assertTrue($body['threads'][0]['mine']);
        self::assertSame(1, $this->notificationsOf($this->owner, 'notes.comment'));

        // The author answers: the reader, in the thread, is told; the author is not.
        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post($this->commentsPath(), ['parentId' => $body['threads'][0]['id'], 'body' => 'Mille deux cents.', 'quote' => 'ignoré']);
        self::assertCount(1, $body['threads'][0]['replies']);
        self::assertNull($body['threads'][0]['replies'][0]['quote']);
        self::assertSame(1, $this->notificationsOf($this->reader, 'notes.comment'));
        self::assertSame(1, $this->notificationsOf($this->owner, 'notes.comment'));
    }

    public function testSomeoneWhoCannotReadTheNoteCannotCommentNorSeeIt(): void
    {
        $this->client->loginUser($this->stranger, 'admin');
        $this->client->request('GET', $this->commentsPath());
        self::assertResponseStatusCodeSame(404);
        $this->post($this->commentsPath(), ['body' => 'Coucou']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyTheAuthorOrAWriterSettlesOrRemoves(): void
    {
        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post($this->commentsPath(), ['body' => 'À relire.']);
        $id = $body['threads'][0]['id'];

        // The reader neither wrote it nor writes the note.
        $this->client->loginUser($this->reader, 'admin');
        $this->post($this->urlGenerator->generate('suite_notes_markdown_comments_resolve', ['commentId' => $id]), ['resolved' => true]);
        self::assertResponseStatusCodeSame(404);
        $this->post($this->urlGenerator->generate('suite_notes_markdown_comments_delete', ['commentId' => $id]), []);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post($this->urlGenerator->generate('suite_notes_markdown_comments_resolve', ['commentId' => $id]), ['resolved' => true]);
        self::assertNotNull($body['threads'][0]['resolvedAt']);
        $body = $this->post($this->urlGenerator->generate('suite_notes_markdown_comments_delete', ['commentId' => $id]), []);
        self::assertSame([], $body['threads']);
    }

    public function testAMentionTellsWhoCanReadAndNobodyElse(): void
    {
        $this->client->loginUser($this->owner, 'admin');
        $content = sprintf('Voir avec @[lecteur](user:%d) et @[inconnu](user:%d).', $this->reader->getId(), $this->stranger->getId());
        $this->post($this->urlGenerator->generate('suite_notes_markdown_update', ['id' => $this->note->getId()]), ['title' => 'Devis Verrier', 'content' => $content]);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->notificationsOf($this->reader, 'notes.mention'));
        self::assertSame(0, $this->notificationsOf($this->stranger, 'notes.mention'), 'a mention must not show a note to someone outside it');

        // Saved again with the same mention: nobody is told twice.
        $this->post($this->urlGenerator->generate('suite_notes_markdown_update', ['id' => $this->note->getId()]), ['title' => 'Devis Verrier', 'content' => $content.' Encore.']);
        self::assertSame(1, $this->notificationsOf($this->reader, 'notes.mention'));
    }

    public function testAGuestCommentsOnlyThroughAWritingLink(): void
    {
        $reading = $this->link(false);
        $this->client->request('GET', $this->urlGenerator->generate('notes_share_comments', ['token' => $reading->getToken(), 'id' => $this->note->getId()]));
        self::assertResponseStatusCodeSame(404);

        $writing = $this->link(true);
        $body = $this->post($this->urlGenerator->generate('notes_share_comments', ['token' => $writing->getToken(), 'id' => $this->note->getId()]), ['guestName' => 'Olivier', 'body' => 'Validé de mon côté.']);
        self::assertResponseIsSuccessful();
        self::assertSame('Olivier', $body['threads'][0]['authorName']);
        self::assertTrue($body['threads'][0]['guest']);
        self::assertSame(1, $this->notificationsOf($this->owner, 'notes.comment'));
    }

    private function commentsPath(): string
    {
        return $this->urlGenerator->generate('suite_notes_markdown_comments', ['id' => $this->note->getId()]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function notificationsOf(User $user, string $type): int
    {
        return count($this->entityManager->getRepository(Notification::class)->findBy(['recipient' => $user->getId(), 'type' => $type]));
    }

    private function person(string $name): User
    {
        $user = new User();
        $user->setEmail(sprintf('%s-%s@aurora.test', $name, bin2hex(random_bytes(4))))
            ->setName($name)
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['notes.markdown.use'])
            ->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->created[] = [User::class, (int) $user->getId()];

        return $user;
    }

    private function link(bool $canWrite): MarkdownNoteShareLink
    {
        $link = new MarkdownNoteShareLink();
        $link->setNote($this->note);
        $link->setCanWrite($canWrite);

        $this->entityManager->persist($link);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNoteShareLink::class, (int) $link->getId()];

        return $link;
    }
}
