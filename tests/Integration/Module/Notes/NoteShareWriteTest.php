<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRevisionRepository;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMember;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;

/**
 * A share link that may write, and the four things that hold it in place.
 *
 * This is the only write endpoint in Aurora with no account behind it: the
 * address *is* the identity. So the tests here are mostly about what it
 * refuses - a link without the switch, a note the link does not own, a save
 * that started from an outdated version - and about the trace it leaves, which
 * is what makes a mistake recoverable.
 */
final class NoteShareWriteTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $userRepository = static::getContainer()->get(UserRepository::class);
        $owner = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;
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

    /** The whole point: no account, and the text changes. */
    public function testALinkOpenedForWritingRewritesItsNote(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);
        // Read before the save: the test shares the entity manager with the
        // request, so the entity in hand is already the bumped one afterwards.
        $before = $note->getVersion();

        $body = $this->save($link, $note, ['title' => 'Procédure', 'content' => 'Relue par l\'invité']);
        self::assertResponseIsSuccessful();
        self::assertSame($before + 1, $body['version'] ?? null);

        $this->entityManager->clear();
        self::assertSame('Relue par l\'invité', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** A locked note is read, not written, through a link too (09/10/2026). */
    public function testALockedNoteCannotBeWrittenThroughALink(): void
    {
        $note = $this->note('Verrouillée', 'Le texte d\'origine');
        $note->setLocked(true);
        $this->entityManager->flush();
        $link = $this->link($note, canWrite: true);

        $this->save($link, $note, ['title' => 'Verrouillée', 'content' => 'Forcée']);
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        self::assertSame('Le texte d\'origine', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** Without the switch, the address reads and nothing else. */
    public function testALinkWithoutTheSwitchCannotWrite(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note);

        $this->save($link, $note, ['content' => 'Détournée']);
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        self::assertSame('Le texte d\'origine', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /**
     * The restriction that cannot be configured away.
     *
     * `includeLinked` widens what a share *shows*; a note citing two notes
     * that each cite two more reaches most of a vault in three hops, and
     * handing that set to whoever holds an address is not something a
     * checkbox should be able to do.
     */
    public function testAWritingLinkWritesItsOwnNoteAndNoOtherInScope(): void
    {
        $linked = $this->note('Comptes 2026', 'Les chiffres');
        $root = $this->note('Sommaire', 'Voir [[Comptes 2026]]');
        $link = $this->link($root, includeLinked: true, canWrite: true);

        // In scope for reading: the share page serves it.
        $this->client->request('GET', $this->urlGenerator->generate('notes_share_note', [
            'token' => $link->getToken(),
            'id' => $linked->getId(),
        ]));
        self::assertResponseIsSuccessful();

        // Out of scope for writing, all the same.
        $this->save($link, $linked, ['content' => 'Détournée']);
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        self::assertSame('Les chiffres', $this->entityManager->find(MarkdownNote::class, $linked->getId())?->getContent());
    }

    /** A revoked link stops writing the instant it is revoked. */
    public function testARevokedLinkStopsWriting(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);
        $link->revoke(new DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->save($link, $note, ['content' => 'Détournée']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A note in the trash stops being writable, link or no link.
     *
     * Deleting it is the clearest statement there is that its owner no longer
     * wants it changed, and the guest has no way of knowing it happened.
     */
    public function testATrashedNoteIsNotWritableThroughALink(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);

        $note->setDeletedAt(new DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->save($link, $note, ['content' => 'Détournée']);
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        self::assertSame('Le texte d\'origine', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** An expired one too, without anybody having to revoke it. */
    public function testAnExpiredLinkStopsWriting(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);
        $link->setExpiresAt(new DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $this->save($link, $note, ['content' => 'Détournée']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Two people on the same note, and neither loses their text.
     *
     * The guest's page started from version N; somebody saved in the
     * meantime, so the guest's save is refused with the current version
     * rather than silently overwriting.
     */
    public function testASaveFromAnOutdatedVersionIsRefused(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);
        $stale = $note->getVersion();

        $this->save($link, $note, ['content' => 'Première écriture', 'version' => $stale]);
        self::assertResponseIsSuccessful();

        $body = $this->save($link, $note, ['content' => 'Écrase tout', 'version' => $stale]);
        self::assertResponseStatusCodeSame(409);
        self::assertTrue($body['conflict'] ?? false);

        $this->entityManager->clear();
        self::assertSame('Première écriture', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** Overwriting knowingly is allowed, as it is inside the back office. */
    public function testForcingThroughOverwritesOnPurpose(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);
        $stale = $note->getVersion();

        $this->save($link, $note, ['content' => 'Première écriture', 'version' => $stale]);
        $this->save($link, $note, ['content' => 'Écrase sciemment', 'version' => $stale, 'force' => true]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('Écrase sciemment', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /**
     * What makes a guest's mistake recoverable, and what answers "who wrote
     * this" when there is no account to name.
     */
    public function testTheStateBeforeAGuestWriteIsKeptAndNamesTheLink(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);
        $link->setLabel('Pour Marie, relecture');
        $this->entityManager->flush();

        $this->save($link, $note, ['content' => 'Relue par l\'invité']);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $revisionRepository = static::getContainer()->get(MarkdownNoteRevisionRepository::class);
        self::assertInstanceOf(MarkdownNoteRevisionRepository::class, $revisionRepository);

        $reloaded = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertInstanceOf(MarkdownNote::class, $reloaded);
        $latest = $revisionRepository->findLatestForNote($reloaded);

        self::assertInstanceOf(MarkdownNoteRevision::class, $latest);
        self::assertSame('Le texte d\'origine', $latest->getContent());
        // No account behind the write: the link is what names it.
        self::assertNull($latest->getAuthor());
        self::assertTrue($latest->wasWrittenThroughLink());
        self::assertSame('Pour Marie, relecture', $latest->getLinkLabel());
    }

    /**
     * The history does not hand a link's recipient to everybody the note is
     * open to.
     *
     * That address belongs to whoever created the link. Somebody the note was
     * handed to as a reader may see *that* a version came through a link -
     * otherwise it reads as a gap in the record - and not which one.
     */
    public function testAReaderOfTheNoteIsNotToldWhichLinkWroteAVersion(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);
        $link->setRecipientEmail('olivier@atelier-verrier.test');
        $this->entityManager->flush();

        $this->save($link, $note, ['content' => 'Relue par l\'invité']);
        self::assertResponseIsSuccessful();

        // Handed the note as a reader, and nothing more: the note lives in
        // the owner's personal space, which is closed to them.
        $reader = $this->reader();
        $member = new MarkdownNoteMember();
        $member->setNote($note)->setUser($reader)->setRole(NoteMemberRoleEnum::Reader);
        $this->entityManager->persist($member);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNoteMember::class, (int) $member->getId()];

        $this->client->loginUser($reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('olivier@atelier-verrier.test', $body);
        self::assertStringContainsString('"viaShareLink":true', $body);

        // The owner, who made the link, still reads its words: they are
        // already on the share screen.
        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => $note->getId()]));
        self::assertStringContainsString('olivier@atelier-verrier.test', (string) $this->client->getResponse()->getContent());
    }

    /** Somebody the note was handed to, as a reader and nothing more. */
    private function reader(): User
    {
        $reader = new User();
        $reader->setEmail(sprintf('lecteur-%s@aurora.test', bin2hex(random_bytes(4))))
            ->setName('lecteur')
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['notes.markdown.use'])
            ->setPassword('x');
        $this->entityManager->persist($reader);
        $this->entityManager->flush();
        $this->created[] = [User::class, (int) $reader->getId()];

        return $reader;
    }

    /**
     * The narrow door: a guest payload cannot refile the note or retag it.
     *
     * The ordinary save applies a whole input; this route applies the text,
     * which is why it goes through `updateText()` instead.
     */
    public function testAGuestPayloadCannotReachAnythingButTheText(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $note->setTags(['projet']);
        $this->entityManager->flush();
        $link = $this->link($note, canWrite: true);
        $spaceId = $note->getSpace()->getId();

        $this->save($link, $note, [
            'content' => 'Relue',
            'tags' => ['detourne'],
            'folderId' => 999999,
            'spaceId' => 999999,
            'appearance' => 'paper',
            'template' => true,
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertSame('Relue', $reloaded?->getContent());
        self::assertSame(['projet'], $reloaded?->getTags());
        self::assertNull($reloaded?->getFolder());
        self::assertSame($spaceId, $reloaded?->getSpace()->getId());
        self::assertFalse($reloaded?->isTemplate());
    }

    /** The page says what it can do, and the header stops saying "read only". */
    public function testThePageAnnouncesThatItMayWrite(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note, canWrite: true);

        $this->client->request('GET', $this->urlGenerator->generate('notes_share', ['token' => $link->getToken()]));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('&quot;canWrite&quot;:true', $html);
        // The address to save to, handed down with the page: the front never
        // builds it, so it has to be there.
        self::assertStringContainsString($link->getToken().'\/'.$note->getId().'\/save', $html);
        // And the header stops saying "read only", which would be the one lie
        // this page must not tell.
        self::assertStringNotContainsString('Lecture seule', $html);
    }

    /** A read-only link hands the page no address to save to. */
    public function testAReadOnlyPageIsToldItCannotWrite(): void
    {
        $note = $this->note('Procédure', 'Le texte d\'origine');
        $link = $this->link($note);

        $this->client->request('GET', $this->urlGenerator->generate('notes_share', ['token' => $link->getToken()]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('&quot;canWrite&quot;:false', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function save(MarkdownNoteShareLinkInterface $link, MarkdownNote $note, array $payload): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('notes_share_save', ['token' => $link->getToken(), 'id' => $note->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function note(string $title, string $content = ''): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->owner);
        $note->setSpace($this->personalSpaceOf($this->owner));
        $note->setTitle($title);
        $note->setContent($content);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        return $note;
    }

    private function link(MarkdownNote $note, bool $includeLinked = false, bool $canWrite = false): MarkdownNoteShareLinkInterface
    {
        $link = new MarkdownNoteShareLink();
        $link->setNote($note);
        $link->setIncludeLinked($includeLinked);
        $link->setCanWrite($canWrite);
        $this->entityManager->persist($link);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNoteShareLink::class, (int) $link->getId()];

        return $link;
    }
}
