<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Core\Search\SearchSnippetBuilder;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Search\NotesBackendSearchProvider;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function bin2hex;
use function random_bytes;

/**
 * The notebook in the global search.
 *
 * Titles and bodies are encrypted at rest, so the search runs in memory over
 * the reader's own decrypted notes: these tests are about that being true -
 * a match is found in the title and in the body, somebody else's notes never
 * are, and the module's switches and privilege are asked like on its screens.
 */
final class NotesSearchTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private NotesBackendSearchProvider $provider;

    private SettingRepository $settings;

    private string $needle;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->provider = $container->get(NotesBackendSearchProvider::class);
        $this->settings = $container->get(SettingRepository::class);

        $this->needle = 'licorne'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // L'espace personnel d'un compte part avec lui, par la base : Doctrine
        // ne doit plus le suivre quand le compte est supprimé.
        $this->entityManager->clear();

        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        $this->settings->set(ModuleParameterEnum::NotesBackend->value, '1');
        $this->settings->set(ModuleParameterEnum::NotesMarkdown->value, '1');
        $this->forgetSwitches();

        parent::tearDown();
    }

    public function testATitleMatchComesFirstAndCarriesThePathThatOpensIt(): void
    {
        $owner = $this->accountWith(['notes.markdown.use']);
        $folder = $this->folder($owner, 'Carnet de voyage');
        $inBody = $this->note($owner, 'Liste de courses', 'Acheter du pain et une '.$this->needle.' en peluche.');
        $inTitle = $this->note($owner, 'Projet '.$this->needle, 'Rien de particulier.', $folder);

        $this->client->loginUser($owner, 'admin');
        $rows = $this->provider->search($this->needle)['notes'];

        self::assertSame(['Projet '.$this->needle, 'Liste de courses'], array_column($rows, 'title'));
        self::assertSame('/backend/notes/markdown/'.$inTitle->getId(), $rows[0]['path']);
        self::assertSame('/backend/notes/markdown/'.$inBody->getId(), $rows[1]['path']);

        // A title match says where the note is filed; a body match says why
        // it came up, on one line.
        self::assertSame('Carnet de voyage', $rows[0]['subtitle']);
        self::assertStringContainsString($this->needle.' en peluche', $rows[1]['subtitle']);
    }

    /** Encrypted at rest, and still found whatever the case of the query. */
    public function testTheMatchIgnoresCase(): void
    {
        $owner = $this->accountWith(['notes.markdown.use']);
        $this->note($owner, 'Idées', 'Un texte sur la '.$this->needle.'.');

        $this->client->loginUser($owner, 'admin');

        self::assertCount(1, $this->provider->search(mb_strtoupper($this->needle))['notes']);
    }

    /** The reason this provider reads the signed-in user. */
    public function testOnlyTheSpacesTheReaderOpensAreSearched(): void
    {
        $owner = $this->accountWith(['notes.markdown.use']);
        $other = $this->accountWith(['notes.markdown.use']);
        $this->note($other, 'Secret '.$this->needle, 'Personnel.');

        // The global search covers what the notebook covers: the spaces the
        // reader can open, never somebody's personal notebook.
        $space = new NoteSpace();
        $space->setOwner($other)->setName('Équipe')->setAccess(NoteSpaceAccessEnum::Backoffice);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->created[] = $space;

        $shared = $this->note($other, 'Partagée '.$this->needle, 'Pour tous.');
        $shared->setSpace($space);
        $this->entityManager->flush();

        $this->client->loginUser($owner, 'admin');

        self::assertSame(['Partagée '.$this->needle], array_column($this->provider->search($this->needle)['notes'], 'title'));
    }

    public function testATrashedNoteIsNotReturned(): void
    {
        $owner = $this->accountWith(['notes.markdown.use']);
        $note = $this->note($owner, 'Jetée '.$this->needle, '');
        $note->setDeletedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->client->loginUser($owner, 'admin');

        self::assertSame([], $this->provider->search($this->needle)['notes']);
    }

    public function testAnAccountWithoutThePrivilegeFindsNothing(): void
    {
        $owner = $this->accountWith(['general.search.view']);
        $this->note($owner, 'Projet '.$this->needle, '');

        $this->client->loginUser($owner, 'admin');

        self::assertSame([], $this->provider->search($this->needle));
    }

    public function testTheModuleSwitchedOffAnswersNothing(): void
    {
        $owner = $this->accountWith(['notes.markdown.use']);
        $this->note($owner, 'Projet '.$this->needle, '');
        $this->client->loginUser($owner, 'admin');

        self::assertCount(1, $this->provider->search($this->needle)['notes']);

        $this->settings->set(ModuleParameterEnum::NotesMarkdown->value, '0');
        $this->forgetSwitches();
        self::assertSame([], $this->provider->search($this->needle));

        $this->settings->set(ModuleParameterEnum::NotesMarkdown->value, '1');
        $this->settings->set(ModuleParameterEnum::NotesBackend->value, '0');
        $this->forgetSwitches();
        self::assertSame([], $this->provider->search($this->needle));
    }

    public function testWithNobodySignedInItReturnsNothing(): void
    {
        self::assertSame([], $this->provider->search($this->needle));
    }

    /** The contract: one module failing must not take the search box down. */
    public function testAnInternalFailureDoesNotThrow(): void
    {
        $owner = $this->accountWith(['notes.markdown.use']);
        $this->client->loginUser($owner, 'admin');

        $notes = $this->createStub(MarkdownNoteRepository::class);
        $notes->method('findAllWithContentForUser')->willThrowException(new RuntimeException('Déchiffrement impossible'));

        $container = self::getContainer();
        $provider = new NotesBackendSearchProvider(
            $notes,
            $container->get(NotesContext::class),
            $container->get(Security::class),
            $container->get(SearchSnippetBuilder::class),
            $container->get(UrlGeneratorInterface::class),
            $container->get(TranslatorInterface::class),
        );

        self::assertSame([], $provider->search($this->needle));
    }

    /** The checker keeps what it read for the request; a test flipping switches starts a new one. */
    private function forgetSwitches(): void
    {
        self::getContainer()->get(ModuleAccessChecker::class)->reset();
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('recherche-notes-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Compte de test')
            ->setType(UserTypeEnum::Backend)
            ->setRoles([UserRoleEnum::User->value])
            ->setPassword('irrelevant')
            ->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->created[] = $user;

        return $user;
    }

    private function folder(User $owner, string $name): NoteFolder
    {
        $folder = new NoteFolder();
        $folder->setUser($owner);
        $folder->setSpace($this->personalSpaceOf($owner));
        $folder->setName($name);

        $this->entityManager->persist($folder);
        $this->entityManager->flush();
        $this->created[] = $folder;

        return $folder;
    }

    private function note(User $owner, string $title, string $content, ?NoteFolder $folder = null): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($owner);
        $note->setSpace($folder?->getSpace() ?? $this->personalSpaceOf($owner));
        $note->setTitle($title);
        $note->setContent($content);
        $note->setFolder($folder);

        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = $note;

        return $note;
    }
}
