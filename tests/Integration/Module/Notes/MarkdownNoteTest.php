<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
    public function testDeletingAParentKeepsItsChildren(): void
    {
        $parent = $this->note($this->owner, 'Parent');
        $child = $this->note($this->owner, 'Enfant', $parent);
        $childId = (int) $child->getId();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_delete', [], ['id' => $parent->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $survivor = $this->entityManager->find(MarkdownNote::class, $childId);
        self::assertInstanceOf(MarkdownNoteInterface::class, $survivor);
        self::assertNull($survivor->getParent(), 'The child should have been lifted to the root.');
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

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
