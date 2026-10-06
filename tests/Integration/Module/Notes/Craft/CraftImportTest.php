<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes\Craft;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Craft\Service\CraftClient;
use Aurora\Module\Notes\Craft\Service\CraftMarkdown;
use Aurora\Module\Notes\Craft\Service\CraftNoteImporter;
use Aurora\Module\Notes\Craft\Setting\CraftSettingEnum;
use Aurora\Module\Notes\Craft\Setting\CraftSettings;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;

/**
 * A Craft document that becomes a note in a notes space.
 *
 * The path crosses four pieces - the connection, the Markdown clean-up, the
 * notes manager and the screen - and each one can break without the other
 * three noticing. What is checked here is the very end: a note exists, in
 * the requested space, it carries the title chosen in the list and the Craft
 * text without its tags, and it remembers where it comes from.
 */
final class CraftImportTest extends IntegrationTestCase
{
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $author;

    private NoteSpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        // Without this, the kernel reboots on the next request and the
        // services set by the test disappear with it: it is the classic test
        // client trap, and it shows as a connection "never opened".
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->author = new User();
        $this->author->setEmail(sprintf('craft-%s@aurora.test', bin2hex(random_bytes(4))))
            ->setName('Camille Craft')
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['notes.markdown.use'])
            ->setPassword('x');
        $this->entityManager->persist($this->author);

        $this->space = new NoteSpace();
        $this->space->setOwner($this->author)->setName('Briefs')->setAccess(NoteSpaceAccessEnum::Members);
        $this->entityManager->persist($this->space);
        $this->entityManager->flush();

        $this->client->loginUser($this->author, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        $space = $this->entityManager->find(NoteSpace::class, $this->space->getId());
        if (null !== $space) {
            $this->entityManager->remove($space);
        }

        $author = $this->entityManager->find(User::class, $this->author->getId());
        if (null !== $author) {
            $this->entityManager->remove($author);
        }

        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheScreenSaysWhenNoConnectionWasEverOpened(): void
    {
        $this->givenCraft(enabled: false, responses: []);

        $this->client->request('GET', $this->url('suite_notes_craft_documents'));

        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertFalse($payload['configured']);
        self::assertSame([], $payload['documents']);
        self::assertFalse($payload['reachable']);
    }

    public function testTheListIsWhatTheConnectionLetsThrough(): void
    {
        $this->givenCraft(enabled: true, responses: [
            new MockResponse((string) json_encode(['items' => [
                ['rootBlockId' => 'doc-2', 'title' => 'Brief septembre'],
                ['rootBlockId' => 'doc-1', 'title' => 'Atelier'],
            ]]), ['response_headers' => ['content-type' => 'application/json']]),
        ]);

        $this->client->request('GET', $this->url('suite_notes_craft_documents'));

        $payload = $this->payload();

        self::assertTrue($payload['configured']);
        self::assertTrue($payload['reachable']);
        self::assertSame(
            [['id' => 'doc-1', 'title' => 'Atelier'], ['id' => 'doc-2', 'title' => 'Brief septembre']],
            $payload['documents'],
        );
    }

    public function testADocumentBecomesANoteOfTheSpace(): void
    {
        $this->givenCraft(enabled: true, responses: [
            new MockResponse("<page><pageTitle>Le brief de septembre</pageTitle><content>\n  Trois **choses** à faire.\n\n  - Relire\n  - Envoyer\n</content></page>"),
        ]);

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_import'), [
            'documentId' => 'doc-2',
            'title' => 'Le brief de septembre',
            'spaceId' => $this->space->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Le brief de septembre', $this->payload()['note']['title']);

        $note = $this->onlyNote();

        self::assertSame($this->space->getId(), $note->getSpace()->getId());
        self::assertSame('Le brief de septembre', $note->getTitle());
        // Where it comes from, to update it later.
        self::assertSame('doc-2', $note->getCraftDocumentId());
        // The title is not written twice, and Craft's tags are gone.
        self::assertSame("Trois **choses** à faire.\n\n- Relire\n- Envoyer", $note->getContent());
    }

    /** Without a space given, the note lands in the personal space, like a note created by hand. */
    public function testWithoutASpaceTheNoteGoesToThePersonalSpace(): void
    {
        $this->givenCraft(enabled: true, responses: [new MockResponse('Un texte.')]);

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_import'), ['documentId' => 'doc-1', 'title' => 'À moi']);

        self::assertResponseIsSuccessful();
        self::assertSame($this->author->getId(), $this->onlyNote()->getSpace()->getPersonalUser()?->getId());
    }

    /** A space one cannot write in does not exist for the import either. */
    public function testASpaceOneCannotWriteInIsRefused(): void
    {
        $this->givenCraft(enabled: true, responses: [new MockResponse('Un texte.')]);

        $closed = new NoteSpace();
        $closed->setName('Fermé')->setAccess(NoteSpaceAccessEnum::Private);
        $this->entityManager->persist($closed);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_import'), [
            'documentId' => 'doc-1',
            'title' => 'Intrus',
            'spaceId' => $closed->getId(),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->notes());

        $this->entityManager->remove($this->entityManager->getReference(NoteSpace::class, $closed->getId()));
        $this->entityManager->flush();
    }

    /**
     * A Craft image becomes an image of the notes space, and the text follows:
     * an image served from a private Craft space would only show for someone
     * with an account there.
     *
     * The real image service, not a double: a double would have returned the
     * address I had taught it.
     */
    public function testACraftImageBecomesAnImageOfTheSpace(): void
    {
        $this->givenCraft(enabled: true, responses: [
            new MockResponse("Du texte.\n\n![Le studio](https://r.craft.example/abc)\n"),
        ]);

        $container = static::getContainer();
        $container->set(CraftNoteImporter::class, new CraftNoteImporter(
            $container->get(CraftClient::class),
            new CraftMarkdown(),
            $container->get(MarkdownNoteManagerInterface::class),
            $container->get(MarkdownNoteInputFactoryInterface::class),
            $container->get(MarkdownNoteImageService::class),
            $container->get(UrlGeneratorInterface::class),
            new MockHttpClient([new MockResponse((string) base64_decode(self::PIXEL, true), [
                'response_headers' => ['content-type' => 'image/png'],
            ])]),
            new NullLogger(),
        ));

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_import'), [
            'documentId' => 'doc-3',
            'title' => 'Avec une image',
            'spaceId' => $this->space->getId(),
        ]);

        self::assertResponseIsSuccessful();

        $content = (string) $this->onlyNote()->getContent();

        self::assertStringNotContainsString('craft.example', $content);
        self::assertMatchesRegularExpression('#!\[Le studio\]\(/suite/notes/markdown/images/[0-9a-f]{32}\.png\)#', $content);
    }

    /**
     * Refreshing puts the text back on the document's current version, keeps
     * what belongs to Aurora, and leaves the old text in the history.
     */
    public function testRefreshingPutsTheNoteBackOnTheDocumentAndKeepsWhatIsAurorasOwn(): void
    {
        $this->givenCraft(enabled: true, responses: [
            new MockResponse('La première version.'),
            new MockResponse('La seconde version.'),
        ]);

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_import'), [
            'documentId' => 'doc-9',
            'title' => 'Le brief',
            'spaceId' => $this->space->getId(),
        ]);
        self::assertResponseIsSuccessful();

        $note = $this->onlyNote();
        $noteId = (int) $note->getId();
        $note->setTags(['client']);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_refresh', ['id' => $noteId]));

        self::assertResponseIsSuccessful();
        self::assertSame('La seconde version.', $this->payload()['note']['content']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(MarkdownNote::class, $noteId);

        self::assertNotNull($refreshed);
        self::assertSame('La seconde version.', $refreshed->getContent());
        self::assertSame('Le brief', $refreshed->getTitle());
        self::assertSame(['client'], $refreshed->getTags());
        // A single note: refreshing replaces, no second one is born.
        self::assertCount(1, $this->notes());

        $revisions = $this->entityManager->getRepository(MarkdownNoteRevision::class)->findBy(['note' => $noteId]);
        self::assertSame(['La première version.'], array_map(static fn (MarkdownNoteRevision $revision): ?string => $revision->getContent(), $revisions));
    }

    /** A note written by hand has nothing to refresh. */
    public function testANoteThatDoesNotComeFromCraftCannotBeRefreshed(): void
    {
        $this->givenCraft(enabled: true, responses: []);

        $this->client->jsonRequest('POST', $this->url('suite_notes_markdown_create'), ['title' => 'Prise à la main', 'spaceId' => $this->space->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_refresh', ['id' => $this->onlyNote()->getId()]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('notes.craft.errors.not_imported', $this->payload()['error']);
    }

    /** A silent Craft must not produce an empty note carrying a title. */
    public function testASilentCraftCreatesNothing(): void
    {
        $this->givenCraft(enabled: true, responses: [new MockResponse('', ['http_code' => 404])]);

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_import'), ['documentId' => 'absent', 'title' => 'Rien', 'spaceId' => $this->space->getId()]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->notes());
    }

    public function testAnImportWithoutADocumentIsRefused(): void
    {
        $this->givenCraft(enabled: true, responses: []);

        $this->client->jsonRequest('POST', $this->url('suite_notes_craft_import'), ['documentId' => '', 'title' => '']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->notes());
    }

    /**
     * A real client, on a real test transport: reading the response is
     * therefore exercised, only the network is faked.
     *
     * @param list<MockResponse> $responses
     */
    private function givenCraft(bool $enabled, array $responses): void
    {
        $store = [
            CraftSettingEnum::Enabled->value => $enabled ? '1' : '0',
            CraftSettingEnum::Endpoint->value => 'https://connect.example/c/1',
            CraftSettingEnum::Token->value => base64_encode('jeton'),
        ];

        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $store[$key] ?? $default,
        );
        $repository->method('getBoolean')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => '1' === ($store[$key] ?? ($default ? '1' : '0')),
        );

        $encryption = new class implements EncryptionServiceInterface {
            public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }

            public function decrypt(string $encoded): ?string
            {
                $decoded = base64_decode($encoded, strict: true);

                return false === $decoded ? null : $decoded;
            }
        };

        static::getContainer()->set(CraftClient::class, new CraftClient(
            new MockHttpClient($responses),
            new NullLogger(),
            new CraftSettings($repository, $encryption),
        ));
    }

    /** @return list<MarkdownNote> */
    private function notes(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(MarkdownNote::class)->findBy(['user' => $this->author->getId()]);
    }

    private function onlyNote(): MarkdownNote
    {
        $notes = $this->notes();
        self::assertCount(1, $notes);

        return $notes[0];
    }

    /** @param array<string, scalar> $params */
    private function url(string $route, array $params = []): string
    {
        return static::getContainer()->get(UrlGeneratorInterface::class)->generate($route, $params);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $decoded;
    }
}
