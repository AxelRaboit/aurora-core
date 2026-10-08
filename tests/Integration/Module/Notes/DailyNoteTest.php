<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Configuration\Setting\Service\SiteDateFormatter;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Today's note: one per day, in a "Journal" folder of the personal space,
 * opened again rather than written twice, and never in somebody else's
 * notebook.
 */
final class DailyNoteTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    private User $other;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $users = static::getContainer()->get(UserRepository::class);

        $owner = $users->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;

        $other = $users->findOneBy(['type' => UserTypeEnum::Suite->value, 'email' => 'daily@aurora.test']);
        if (!$other instanceof User) {
            $other = new User();
            $other->setEmail('daily@aurora.test');
            $other->setName('Autre');
            $other->setType(UserTypeEnum::Suite);
            $other->setPassword('x');
            $other->setRoles($owner->getRoles());
            $this->entityManager->persist($other);
            $this->entityManager->flush();
            $this->created[] = [User::class, (int) $other->getId()];
        }
        $this->other = $other;
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

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

    public function testTheFirstCallWritesTodaysNoteInANewJournal(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        self::assertSame([], $this->journalsOf($this->owner));

        $id = $this->openDaily();

        // Looked up before the note: the lookup empties the entity manager.
        $journals = $this->journalsOf($this->owner);
        self::assertCount(1, $journals);

        $note = $this->entityManager->find(MarkdownNote::class, $id);
        self::assertInstanceOf(MarkdownNote::class, $note);
        self::assertSame($this->todaysTitle(), $note->getTitle());
        self::assertSame("# {$this->todaysTitle()}\n\n", $note->getContent());
        self::assertSame($journals[0]->getId(), $note->getFolder()?->getId());
        self::assertTrue($note->getSpace()->isPersonal());
        self::assertSame($this->personalSpaceOf($this->owner)->getId(), $note->getSpace()->getId());
    }

    public function testASecondCallTheSameDayOpensTheSameNote(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $first = $this->openDaily();
        $second = $this->openDaily();

        self::assertSame($first, $second);
        self::assertCount(1, $this->journalsOf($this->owner));
    }

    /** A template named like the button gives the note its text, `{{date}}` filled in. */
    public function testATemplateNamedNoteDuJourIsUsed(): void
    {
        $template = $this->note($this->owner, 'Note du jour', "## Humeur du {{date}}\n\n- Priorités :");

        $this->client->loginUser($this->owner, 'admin');
        $id = $this->openDaily();

        $note = $this->entityManager->find(MarkdownNote::class, $id);
        $today = static::getContainer()->get(SiteDateFormatter::class)->date(new DateTimeImmutable(), 'fr');

        self::assertSame($this->todaysTitle(), $note?->getTitle());
        self::assertSame("## Humeur du {$today}\n\n- Priorités :", $note?->getContent());
        self::assertFalse($note?->isTemplate());
        self::assertSame("## Humeur du {{date}}\n\n- Priorités :", $this->entityManager->find(MarkdownNote::class, $template->getId())?->getContent());
    }

    /**
     * Each person has their own journal: someone else's call neither finds
     * your note nor writes into your space, and your template stays yours.
     */
    public function testAnotherPersonsPersonalSpaceIsNeverTouched(): void
    {
        $this->note($this->owner, 'Note du jour', 'Le modèle de quelqu’un d’autre');

        $this->client->loginUser($this->owner, 'admin');
        $ownersNote = $this->openDaily();

        $this->client->loginUser($this->other, 'admin');
        $othersNote = $this->openDaily();

        self::assertNotSame($ownersNote, $othersNote);

        self::assertCount(1, $this->journalsOf($this->other));
        $ownersJournals = $this->journalsOf($this->owner);
        self::assertCount(1, $ownersJournals);
        $othersSpaceId = $this->personalSpaceOf($this->other)->getId();

        $note = $this->entityManager->find(MarkdownNote::class, $othersNote);
        self::assertSame($othersSpaceId, $note?->getSpace()->getId());
        self::assertSame("# {$this->todaysTitle()}\n\n", $note?->getContent());
        self::assertNotSame($ownersJournals[0]->getId(), $note?->getFolder()?->getId());

        $inOwnersJournal = $this->entityManager->getRepository(MarkdownNote::class)->findBy(['folder' => $ownersJournals[0]->getId()]);
        self::assertSame([$ownersNote], array_map(static fn (MarkdownNote $one): int => (int) $one->getId(), $inOwnersJournal));
    }

    /** Opens today's note and returns its id, the entity manager emptied. */
    private function openDaily(): int
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_daily'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $id = (int) $body['note']['id'];

        $this->entityManager->clear();
        $note = $this->entityManager->find(MarkdownNote::class, $id);
        self::assertInstanceOf(MarkdownNote::class, $note);

        // The note first, then its folder, so the folder goes last at the end.
        $folderId = $note->getFolder()?->getId();
        if (null !== $folderId && !in_array([NoteFolder::class, $folderId], $this->created, true)) {
            $this->created[] = [NoteFolder::class, $folderId];
        }
        if (!in_array([MarkdownNote::class, $id], $this->created, true)) {
            $this->created[] = [MarkdownNote::class, $id];
        }

        return $id;
    }

    /** @return list<NoteFolderInterface> the living "Journal" folders at the root of the person's space */
    private function journalsOf(User $user): array
    {
        $this->entityManager->clear();
        $folders = static::getContainer()->get(NoteFolderRepository::class)->findLivingInSpace($this->personalSpaceOf($user));

        return array_values(array_filter(
            $folders,
            static fn (NoteFolderInterface $folder): bool => null === $folder->getParent() && 'Journal' === $folder->getName(),
        ));
    }

    private function todaysTitle(): string
    {
        return static::getContainer()->get(SiteDateFormatter::class)->date(new DateTimeImmutable(), 'fr', 'full');
    }

    private function note(User $user, string $title, string $content): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($user);
        $note->setSpace($this->personalSpaceOf($user));
        $note->setTitle($title);
        $note->setContent($content);
        $note->setTemplate(true);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        return $note;
    }
}
