<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteArchive;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use ZipArchive;

/**
 * Markdown notes, brought back into core from the archived Notes package.
 *
 * The package shipped with a thorough front-end suite and no server-side tests
 * at all, so the properties that actually protect somebody's notes were the
 * untested ones. These cover the two that matter: a note belongs to one person
 * and reaches nobody else, and deleting a note does not take the notes filed
 * under it down as well.
 */
final class MarkdownNoteTest extends IntegrationTestCase
{
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

        $owner = $users->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;

        $other = $users->findOneBy(['type' => UserTypeEnum::Backend->value, 'email' => 'notes@aurora.test']);
        if (!$other instanceof User) {
            $other = new User();
            $other->setEmail('notes@aurora.test');
            $other->setName('Autre');
            $other->setType(UserTypeEnum::Backend);
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

    public function testANoteIsCreatedAndComesBackInTheList(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post('backend_notes_markdown_create', [
            'title' => 'Première note',
            'content' => "# Titre\n\nDu texte.",
            'tags' => ['essai'],
        ]);

        self::assertResponseIsSuccessful();
        $id = (int) $body['note']['id'];
        $this->created[] = [MarkdownNote::class, $id];

        self::assertContains($id, $this->listedIds());
    }

    /**
     * The property the whole module rests on.
     *
     * Notes are encrypted at rest precisely because people write things there
     * they would not put elsewhere; that is worth nothing if the list hands them
     * to the next account that asks.
     */
    public function testSomebodyElsesNoteIsNeitherListedNorReadable(): void
    {
        $note = $this->note($this->owner, 'Note privée');

        $this->client->loginUser($this->other, 'admin');

        self::assertNotContains($note->getId(), $this->listedIds());

        $this->client->request('GET', $this->urlGenerator->generate(
            'backend_notes_markdown_show',
            ['id' => $note->getId()],
        ));
        self::assertResponseStatusCodeSame(404);
    }

    /** Writing to a note you do not own is refused, not silently applied. */
    public function testSomebodyElsesNoteCannotBeEdited(): void
    {
        $note = $this->note($this->owner, 'Intacte');

        $this->client->loginUser($this->other, 'admin');
        $this->post('backend_notes_markdown_update', ['title' => 'Piratée'], ['id' => $note->getId()]);

        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertInstanceOf(MarkdownNoteInterface::class, $fresh);
        self::assertSame('Intacte', $fresh->getTitle());
    }

    /**
     * Deleting a parent lifts its children to the root, it does not delete them.
     *
     * The column says `ON DELETE SET NULL`; this is the test that says why that
     * was the right choice. Losing a page because you deleted the folder above
     * it is not a trade anybody offered.
     */
    /**
     * Deleting a parent takes its children to the trash with it.
     *
     * They used to be lifted to the root, which lost the filing for good. The
     * branch now leaves whole and comes back whole, and the child keeps its
     * parent link throughout - that link is what a restore puts back.
     */
    public function testDeletingAParentTakesItsChildrenToTheTrash(): void
    {
        $parent = $this->note($this->owner, 'Parent');
        $child = $this->note($this->owner, 'Enfant', $parent);
        $childId = (int) $child->getId();
        $parentId = (int) $parent->getId();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_delete', [], ['id' => $parentId]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $trashedChild = $this->entityManager->find(MarkdownNote::class, $childId);
        self::assertInstanceOf(MarkdownNoteInterface::class, $trashedChild);
        self::assertTrue($trashedChild->isTrashed(), 'The child should have followed its parent.');
        self::assertSame($parentId, $trashedChild->getTrashedWithNoteId());
        self::assertNotNull($trashedChild->getParent(), 'The filing has to survive for the restore to mean anything.');
    }

    public function testRestoringAParentBringsItsChildrenBack(): void
    {
        $parent = $this->note($this->owner, 'Parent');
        $child = $this->note($this->owner, 'Enfant', $parent);
        $childId = (int) $child->getId();
        $parentId = (int) $parent->getId();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_delete', [], ['id' => $parentId]);
        $this->post('backend_notes_markdown_restore', [], ['id' => $parentId]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $restored = $this->entityManager->find(MarkdownNote::class, $childId);
        self::assertInstanceOf(MarkdownNoteInterface::class, $restored);
        self::assertFalse($restored->isTrashed());
        self::assertNull($restored->getTrashedWithNoteId());
    }

    public function testANoteTrashedOnItsOwnStaysThereWhenItsParentComesBack(): void
    {
        $parent = $this->note($this->owner, 'Parent');
        $child = $this->note($this->owner, 'Enfant', $parent);
        $childId = (int) $child->getId();
        $parentId = (int) $parent->getId();

        $this->client->loginUser($this->owner, 'admin');
        // The child goes first, by hand: that is a decision of its own.
        $this->post('backend_notes_markdown_delete', [], ['id' => $childId]);
        $this->post('backend_notes_markdown_delete', [], ['id' => $parentId]);
        $this->post('backend_notes_markdown_restore', [], ['id' => $parentId]);

        $this->entityManager->clear();
        $stillTrashed = $this->entityManager->find(MarkdownNote::class, $childId);
        self::assertInstanceOf(MarkdownNoteInterface::class, $stillTrashed);
        self::assertTrue($stillTrashed->isTrashed(), 'A note deleted on purpose must not be resurrected by a branch restore.');
    }

    /** Title and body are ciphertext in the database, and readable through the ORM. */
    public function testTheBodyIsEncryptedAtRest(): void
    {
        $secret = 'Phrase que personne ne doit lire en base';
        $note = $this->note($this->owner, 'Secrète', content: $secret);

        $stored = $this->entityManager->getConnection()->fetchOne(
            'SELECT content FROM core_notes_markdown_notes WHERE id = ?',
            [$note->getId()],
        );

        self::assertIsString($stored);
        self::assertStringNotContainsString($secret, $stored, 'The body reached the column in clear.');

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertInstanceOf(MarkdownNoteInterface::class, $fresh);
        self::assertSame($secret, $fresh->getContent());
    }

    private function note(User $user, string $title, ?MarkdownNote $parent = null, string $content = ''): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($user);
        $note->setTitle($title);
        $note->setContent($content);
        if (null !== $parent) {
            $note->setParent($parent);
        }
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        return $note;
    }

    /** @return list<int> */
    private function listedIds(): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_list'));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): int => (int) $row['id'], $body['notes']);
    }

    /**
     * @return array<string, mixed>
     */

    /**
     * **L'aller-retour, qui est la seule preuve qu'une exportation vaut.**.
     *
     * Un carnet exporté puis réimporté doit redonner la même arborescence, les
     * mêmes titres et les mêmes étiquettes. Sans ça, l'export est un tas de
     * fichiers, pas une porte de sortie.
     */
    public function testTheNotebookSurvivesAnExportAndAnImport(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $parent = $this->post('backend_notes_markdown_create', [
            'title' => 'Clients',
            'content' => 'La porte du carnet.',
            'tags' => ['index'],
        ]);
        $parentId = $parent['note']['id'];
        $this->created[] = [MarkdownNote::class, (int) $parentId];

        $child = $this->post('backend_notes_markdown_create', [
            'title' => 'Studio Lumen',
            'content' => 'Photo, en cours.',
            'parentId' => $parentId,
        ]);
        $this->created[] = [MarkdownNote::class, (int) $child['note']['id']];

        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_export'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // La route rend un fichier, pas un corps : `getContent()` y répond faux.
        // Et celui-ci s'efface une fois envoyé, ce qui est voulu. L'archive
        // examinée est donc celle que le service refabrique, et la route est
        // pesée sur ce qu'elle promet - un fichier, et deux cents.
        self::assertInstanceOf(BinaryFileResponse::class, $this->client->getResponse());

        $path = static::getContainer()->get(MarkdownNoteArchive::class)->zipFor($this->owner);

        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));

        // L'arborescence est dans les chemins : une note fille est un fichier
        // dans le dossier du nom de sa mère.
        $entries = [];
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $entries[] = (string) $archive->getNameIndex($i);
        }
        $archive->close();

        self::assertContains('Clients.md', $entries);
        self::assertContains('Clients/Studio Lumen.md', $entries);

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_import'),
            files: ['files' => [new UploadedFile($path, 'notes.zip', 'application/zip', null, true)]],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Deux notes, pas trois : le dossier traversé compte pour la note
        // qu'il est, une seule fois.
        self::assertSame(2, $body['created']);

        $titles = [];
        foreach ($this->notes() as $note) {
            $titles[] = $note->getTitle();
            $this->created[] = [MarkdownNote::class, (int) $note->getId()];
        }

        // Rien n'est écrasé : les originales et les importées cohabitent.
        self::assertSame(2, count(array_filter($titles, static fn (?string $title): bool => 'Clients' === $title)));

        $imported = null;
        foreach ($this->notes() as $note) {
            if ('Studio Lumen' === $note->getTitle() && $note->getId() !== $child['note']['id']) {
                $imported = $note;
            }
        }

        self::assertInstanceOf(MarkdownNoteInterface::class, $imported, 'la note fille est revenue');
        self::assertSame('Clients', $imported->getParent()?->getTitle(), 'et sous sa mère');
    }

    /** Les étiquettes voyagent en préambule, et reviennent comme étiquettes. */
    public function testTagsSurviveTheRoundTrip(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $note = $this->post('backend_notes_markdown_create', [
            'title' => 'Avec étiquettes',
            'content' => 'Du texte.',
            'tags' => ['photo', 'méthode'],
        ]);
        $this->created[] = [MarkdownNote::class, (int) $note['note']['id']];

        $this->client->request(
            'GET',
            $this->urlGenerator->generate('backend_notes_markdown_export_one', ['id' => $note['note']['id']]),
        );

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringStartsWith("---\ntags: [photo, méthode]\n---", $body);

        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-note-test-');
        file_put_contents($path, $body);

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_import'),
            files: ['files' => [new UploadedFile($path, 'Avec étiquettes.md', 'text/markdown', null, true)]],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $imported = null;
        foreach ($this->notes() as $candidate) {
            $this->created[] = [MarkdownNote::class, (int) $candidate->getId()];

            if ('Avec étiquettes' === $candidate->getTitle() && $candidate->getId() !== $note['note']['id']) {
                $imported = $candidate;
            }
        }

        self::assertInstanceOf(MarkdownNoteInterface::class, $imported);
        self::assertSame(['photo', 'méthode'], $imported->getTags());
        // Le préambule n'est pas resté dans le texte.
        self::assertSame('Du texte.', mb_trim((string) $imported->getContent()));
    }

    /** @return list<MarkdownNoteInterface> */
    private function notes(): array
    {
        $this->entityManager->clear();

        return static::getContainer()
            ->get(MarkdownNoteRepository::class)
            ->findAllWithContentForUser($this->owner);
    }

    private function post(string $route, array $payload = [], array $params = []): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate($route, $params),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
