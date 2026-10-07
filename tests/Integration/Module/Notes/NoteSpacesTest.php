<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteArchive;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Service\SharedNoteScope;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMember;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Manager\NoteSpaceManagerInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use ZipArchive;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function base64_decode;
use function bin2hex;
use function file_put_contents;
use function html_entity_decode;
use function json_decode;
use function json_encode;
use function preg_match;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Spaces: each one says who reads it and who writes it, and nothing else
 * decides.
 *
 * Accounts without an administrator role, on purpose: an administrator has
 * every right in Aurora, and a test run with one would prove nothing about a
 * space's roles.
 */
final class NoteSpacesTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    /** Creates the shared space: they are its owner. */
    private User $owner;

    /** Added as an editor. */
    private User $editor;

    /** Inscrit comme lecteur. */
    private User $reader;

    /** Inscrit nulle part. */
    private User $outsider;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // A single kernel for the whole test: the accounts and spaces created
        // here stay the ones the requests see.
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $this->owner = $this->user('proprietaire');
        $this->editor = $this->user('redacteur');
        $this->reader = $this->user('lecteur');
        $this->outsider = $this->user('dehors');
    }

    /**
     * Deleting the accounts is enough: their personal spaces go with them
     * through the database, and the shared spaces they own are deleted
     * separately, with what they hold.
     */
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

    public function testAPersonalNotebookStaysPersonal(): void
    {
        $private = $this->note($this->owner, 'Journal', $this->personalSpaceOf($this->owner));

        $this->client->loginUser($this->outsider, 'admin');
        self::assertNotContains($private->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $private->getId()]));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $private->getId()]));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A space open to the back office is read by everyone, and written
     * according to the role: reading means reading, not editing, and the
     * editor role includes the reader's.
     */
    public function testABackofficeSpaceIsReadByEverybodyAndWrittenByItsEditors(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);
        $note = $this->note($this->owner, 'Procédure', $space, content: 'Le texte d\'origine');

        $this->client->loginUser($this->outsider, 'admin');
        self::assertContains($note->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();

        $this->post('suite_notes_markdown_update', ['title' => 'Détournée', 'content' => 'x', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $note->getId()]));
        self::assertResponseRedirects($this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));

        $this->client->loginUser($this->editor, 'admin');
        $this->post('suite_notes_markdown_update', ['title' => 'Procédure', 'content' => 'Relue', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('Relue', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** A members-only space does not exist for anyone not added to it. */
    public function testAMembersSpaceIsInvisibleToEverybodyElse(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $folder = $this->folder($this->owner, 'Clients', $space);
        $note = $this->note($this->owner, 'Devis', $space, $folder);

        $this->client->loginUser($this->outsider, 'admin');
        self::assertNotContains($note->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_folder', ['id' => $folder->getId()]));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->reader, 'admin');
        self::assertContains($note->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();
    }

    /**
     * Writing follows the role, not the author: a reader does not write the
     * note they wrote when they were an editor, an editor writes someone
     * else's.
     */
    public function testWritingFollowsTheRoleNotTheAuthor(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $note = $this->note($this->reader, 'Écrite par le lecteur', $space);

        $this->client->loginUser($this->reader, 'admin');
        $this->post('suite_notes_markdown_update', ['title' => 'Mienne', 'content' => 'pourtant', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('suite_notes_markdown_update', ['title' => 'Relue', 'content' => 'par le rédacteur', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();
    }

    /** Creating in a space requires writing in it. */
    public function testCreatingInASpaceNeedsTheEditorRole(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);

        $this->client->loginUser($this->reader, 'admin');
        $this->post('suite_notes_markdown_create', ['title' => 'Intruse', 'spaceId' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('suite_notes_markdown_folders_create', ['name' => 'Intrus', 'spaceId' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->editor, 'admin');
        $body = $this->post('suite_notes_markdown_create', ['title' => 'Bienvenue', 'spaceId' => $space->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame($space->getId(), $body['note']['spaceId']);

        // Without a space given, a note is born in the personal space.
        $mine = $this->post('suite_notes_markdown_create', ['title' => 'Pour moi']);
        self::assertResponseIsSuccessful();
        self::assertSame($this->personalSpaceOf($this->editor)->getId(), $mine['note']['spaceId']);
    }

    /**
     * A folder changes space with its whole branch; only someone who can
     * write at both ends moves it.
     */
    public function testAFolderChangesSpaceWithEverythingInIt(): void
    {
        $team = $this->space(NoteSpaceAccessEnum::Backoffice);
        $personal = $this->personalSpaceOf($this->editor);
        $folder = $this->folder($this->editor, 'Onboarding', $personal);
        $sub = $this->folder($this->editor, 'Semaine 1', $personal, $folder);
        $note = $this->note($this->editor, 'Accueil', $personal, $sub);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('suite_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $team->getId()], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame($team->getId(), $this->entityManager->find(NoteFolder::class, $sub->getId())?->getSpace()->getId());
        self::assertSame($team->getId(), $this->entityManager->find(MarkdownNote::class, $note->getId())?->getSpace()->getId());

        // The reader does not take it into their notebook...
        $this->client->loginUser($this->reader, 'admin');
        $this->post('suite_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $this->personalSpaceOf($this->reader)->getId()], ['id' => $folder->getId()]);
        self::assertResponseStatusCodeSame(404);

        // ... and nobody files anything in someone else's notebook.
        $this->client->loginUser($this->editor, 'admin');
        $this->post('suite_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $this->personalSpaceOf($this->reader)->getId()], ['id' => $folder->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->post('suite_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $personal->getId()], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame($personal->getId(), $this->entityManager->find(MarkdownNote::class, $note->getId())?->getSpace()->getId());
    }

    /**
     * A wiki link resolves in the space of the note being read: neither in
     * the reader's, nor in the author's private notebook. And reading gives
     * neither writing nor the author's notebook.
     */
    public function testLinksResolveInsideTheSpaceOfTheNote(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);
        $guide = $this->note($this->owner, 'Guide', $space, content: 'Voir [[Budget]] et [[Secret]]');
        $budget = $this->note($this->owner, 'Budget', $space, content: 'Chiffres');
        $this->note($this->owner, 'Secret', $this->personalSpaceOf($this->owner), content: 'Privé');
        $mine = $this->note($this->reader, 'Budget', $this->personalSpaceOf($this->reader), content: 'Le mien');

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $guide->getId()]));
        self::assertResponseIsSuccessful();

        $props = $this->readProps((string) $this->client->getResponse()->getContent());

        self::assertSame($budget->getId(), $props['titleIndex']['budget'] ?? null);
        self::assertNotSame($mine->getId(), $props['titleIndex']['budget'] ?? null);
        self::assertArrayNotHasKey('secret', $props['titleIndex']);
        self::assertFalse($props['canEdit']);
    }

    /**
     * A space's images show for all its readers, and for them alone; only
     * someone who writes in the space adds them.
     */
    public function testSpaceImagesAreSeenByItsReadersOnly(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);

        $this->client->loginUser($this->reader, 'admin');
        $this->upload($space);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->editor, 'admin');
        $this->upload($space);
        self::assertResponseIsSuccessful();
        $filename = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['filename'];

        $note = $this->note($this->editor, 'Illustrée', $space, content: sprintf('![pixel](/x/%s)', $filename));
        $url = $this->urlGenerator->generate('suite_notes_markdown_images_read', ['noteId' => $note->getId(), 'filename' => $filename]);

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // An image the note does not cite stays closed, even through it.
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_images_read', ['noteId' => $note->getId(), 'filename' => 'autre-'.$filename]));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The share link of a space note serves its images: they live in the
     * space's compartment, not with the author.
     */
    public function testAShareLinkServesTheImagesOfASpaceNote(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);

        $this->client->loginUser($this->editor, 'admin');
        $this->upload($space);
        self::assertResponseIsSuccessful();
        $filename = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['filename'];

        $note = $this->note($this->editor, 'Illustrée', $space, content: sprintf('![pixel](/x/%s)', $filename));
        $link = new MarkdownNoteShareLink();
        $link->setNote($this->managed($note));
        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $this->client->restart();
        $this->client->request('GET', $this->urlGenerator->generate('notes_share_image', ['token' => $link->getToken(), 'filename' => $filename]));
        self::assertResponseIsSuccessful();
    }

    /**
     * A space is exported on its own, at the root of the archive, and an
     * archive is poured into the root of a space one writes in - never
     * another.
     */
    public function testASpaceIsExportedAndImportedOnItsOwn(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $folder = $this->folder($this->owner, 'Guides', $space);
        $this->note($this->owner, 'Accueil', $space, $folder);
        $this->note($this->owner, 'Journal', $this->personalSpaceOf($this->owner));

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_export', ['spaceId' => $space->getId()]));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_export', ['spaceId' => $space->getId()]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('notes-espace-', (string) $this->client->getResponse()->headers->get('Content-Disposition'), "le zip porte le nom de l'espace");

        $path = static::getContainer()->get(MarkdownNoteArchive::class)->zipFor($this->managed($this->owner), $this->managed($space));
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));
        $entries = [];
        for ($entryIndex = 0; $entryIndex < $archive->numFiles; ++$entryIndex) {
            $entries[] = (string) $archive->getNameIndex($entryIndex);
        }
        $archive->close();

        self::assertContains('Guides/Accueil.md', $entries, 'à la racine, sans le dossier de l\'espace');
        self::assertNotContains('Journal.md', $entries, 'rien de son carnet personnel');

        $target = $this->space(NoteSpaceAccessEnum::Members);

        // A reader pours nothing into the space.
        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('suite_notes_markdown_import'), ['spaceId' => (string) $target->getId()], ['files' => [new UploadedFile($path, 'espace.zip', 'application/zip', null, true)]]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->editor, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('suite_notes_markdown_import'), ['spaceId' => (string) $target->getId()], ['files' => [new UploadedFile($path, 'espace.zip', 'application/zip', null, true)]]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $imported = $this->entityManager->getRepository(MarkdownNote::class)->findBy(['space' => $target->getId()]);
        self::assertSame(['Accueil'], array_map(static fn (MarkdownNote $one): string => (string) $one->getTitle(), $imported));
        self::assertSame('Guides', $imported[0]->getFolder()?->getName());
        self::assertSame($target->getId(), $imported[0]->getFolder()?->getSpace()->getId());
    }

    /**
     * A full export files every space in its own folder: the personal one
     * included, an empty one kept, and a personal folder named like a shared
     * space no longer mixed with it.
     */
    public function testAFullExportFilesEverySpaceInItsOwnFolder(): void
    {
        $shared = $this->space(NoteSpaceAccessEnum::Members);
        $this->note($this->owner, 'Accueil', $shared);
        $empty = $this->space(NoteSpaceAccessEnum::Members);
        $personal = $this->personalSpaceOf($this->owner);
        $homonym = $this->folder($this->owner, (string) $shared->getName(), $personal);
        $this->note($this->owner, 'Mes idées', $personal, $homonym);

        $entries = $this->entriesOf(static::getContainer()->get(MarkdownNoteArchive::class)->zipFor($this->managed($this->owner)));
        $mine = static::getContainer()->get(TranslatorInterface::class)->trans('notes.markdown.spaces.my_space').'/';

        self::assertContains($shared->getName().'/Accueil.md', $entries);
        self::assertContains($mine.$shared->getName().'/Mes idées.md', $entries, 'le carnet perso dans son propre dossier');
        self::assertNotContains($shared->getName().'/Mes idées.md', $entries, "plus de mélange avec l'espace du même nom");
        self::assertContains($empty->getName().'/', $entries, "l'espace vide garde son dossier");
    }

    /**
     * A full export's personal folder is unwrapped when it comes back into
     * the personal notebook, whatever language wrote it; poured into a shared
     * space, it stays the folder it is.
     */
    public function testAFullExportComesBackWithoutItsPersonalWrapper(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-test-notes-');
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addEmptyDir('My note space');
        $archive->addFromString('My note space/Rituels/Lundi.md', 'Le point du lundi.');
        $archive->addFromString('Guide partagé/Accueil.md', 'Bienvenue.');
        $archive->close();

        $personal = $this->personalSpaceOf($this->editor);
        $this->client->loginUser($this->editor, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('suite_notes_markdown_import'), [], ['files' => [new UploadedFile($path, 'notes.zip', 'application/zip', null, true)]]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $folders = $this->entityManager->getRepository(NoteFolder::class)->findBy(['space' => $personal->getId()]);
        $names = array_map(static fn (NoteFolder $one): string => (string) $one->getName(), $folders);
        self::assertNotContains('My note space', $names, 'le dossier du carnet perso est déballé, même écrit en anglais');

        $rituals = array_values(array_filter($folders, static fn (NoteFolder $one): bool => 'Rituels' === $one->getName()));
        self::assertCount(1, $rituals);
        self::assertNull($rituals[0]->getParent(), 'son contenu revient à la racine du carnet');
        self::assertContains('Guide partagé', $names, "le dossier d'un espace partagé reste un dossier");

        $target = $this->space(NoteSpaceAccessEnum::Members);
        $this->client->request('POST', $this->urlGenerator->generate('suite_notes_markdown_import'), ['spaceId' => (string) $target->getId()], ['files' => [new UploadedFile($path, 'notes.zip', 'application/zip', null, true)]]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $inSpace = array_map(static fn (NoteFolder $one): string => (string) $one->getName(), $this->entityManager->getRepository(NoteFolder::class)->findBy(['space' => $target->getId()]));
        self::assertContains('My note space', $inSpace, 'versé dans un espace partagé, il reste un dossier');
    }

    /**
     * A folder is exported on its own, its content at the root of an archive
     * named after it, by whoever can read its space and nobody else.
     */
    public function testAFolderIsExportedOnItsOwnUnderItsName(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $folder = $this->folder($this->owner, 'Guides pratiques', $space);
        $child = $this->folder($this->owner, 'Accueil', $space, $folder);
        $this->note($this->owner, 'Premier jour', $space, $child);
        $this->note($this->owner, 'Ailleurs', $space);

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_export', ['folderId' => $folder->getId()]));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_export', ['folderId' => $folder->getId()]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('notes-guides-pratiques-', (string) $this->client->getResponse()->headers->get('Content-Disposition'), 'le zip porte le nom du dossier');

        $entries = $this->entriesOf(static::getContainer()->get(MarkdownNoteArchive::class)->zipFor($this->managed($this->reader), $this->managed($folder)));

        self::assertContains('Accueil/Premier jour.md', $entries, 'le contenu du dossier, à la racine');
        self::assertNotContains('Ailleurs.md', $entries, 'rien hors du dossier');
    }

    /**
     * What sleeps in the trash follows its branch: restored, it finds its
     * folder again in the same space.
     */
    public function testATrashedNoteFollowsItsFolderAcrossSpaces(): void
    {
        $team = $this->space(NoteSpaceAccessEnum::Backoffice);
        $personal = $this->personalSpaceOf($this->editor);
        $folder = $this->folder($this->editor, 'Projet', $personal);
        $trashed = $this->note($this->editor, 'Brouillon jeté', $personal, $folder);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('suite_notes_markdown_delete', [], ['id' => $trashed->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('suite_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $team->getId()], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame($team->getId(), $this->entityManager->find(MarkdownNote::class, $trashed->getId())?->getSpace()->getId());
    }

    /**
     * A space whose owner has left goes back to the administrators: otherwise
     * nobody could configure it or bring it back any more.
     */
    public function testAnOrphanedSpaceIsAdoptedByAdministrators(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $this->managed($space)->setOwner(null);
        $this->entityManager->flush();

        $admin = $this->user('admin');
        $this->managed($admin)->setRoles([UserRoleEnum::Admin->value]);
        $this->entityManager->flush();

        $this->client->loginUser($this->managed($this->editor), 'admin');
        $this->post('suite_notes_spaces_update', ['name' => 'Repris', 'access' => 'members'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404, 'un rédacteur ne gère pas');

        $this->client->loginUser($this->managed($admin), 'admin');
        $this->post('suite_notes_spaces_update', ['name' => 'Repris', 'access' => 'members'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('suite_notes_spaces_delete', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('suite_notes_spaces_restore', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
    }

    /**
     * A space configured from elsewhere: its members write in it, but nobody
     * configures it from here - not its name, its access, its members, its
     * publication or its removal. Its team follows what it is given, and when
     * what configured it disappears it goes to the trash, from where an
     * administrator brings it back.
     */
    public function testAManagedSpaceIsSetFromElsewhere(): void
    {
        $manager = static::getContainer()->get(NoteSpaceManagerInterface::class);
        $space = $manager->createManaged('Boulangerie Martin', 'studio.customer_space');
        $manager->syncManaged($space, 'Boulangerie Martin', [
            ['user' => $this->managed($this->owner), 'role' => NoteSpaceRoleEnum::Manager],
            ['user' => $this->managed($this->editor), 'role' => NoteSpaceRoleEnum::Editor],
        ]);
        $spaceId = (int) $space->getId();

        $this->client->loginUser($this->managed($this->editor), 'admin');
        $this->post('suite_notes_markdown_create', ['title' => 'Brief', 'spaceId' => $spaceId]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->managed($this->owner), 'admin');
        foreach ([
            ['suite_notes_spaces_update', ['name' => 'Renommé', 'access' => 'backoffice'], ['id' => $spaceId]],
            ['suite_notes_spaces_members_set', ['userId' => $this->outsider->getId(), 'role' => 'reader'], ['id' => $spaceId]],
            ['suite_notes_spaces_members_remove', [], ['id' => $spaceId, 'userId' => $this->editor->getId()]],
            ['suite_notes_spaces_delete', [], ['id' => $spaceId]],
        ] as [$route, $payload, $parameters]) {
            $body = $this->post($route, $payload, $parameters);
            self::assertResponseStatusCodeSame(409, $route);
            self::assertSame('notes.markdown.spaces.errors.managed', $body['error'] ?? null, $route);
        }

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_spaces_list'));
        $listed = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['spaces'];
        $row = array_values(array_filter($listed, static fn (array $one): bool => $spaceId === (int) $one['id']))[0] ?? [];
        self::assertTrue($row['managed'] ?? false);
        self::assertSame('manager', $row['role'] ?? null);

        // The team changes over there: the editor leaves, the outsider arrives.
        $this->entityManager->clear();
        $manager->syncManaged($this->entityManager->find(NoteSpace::class, $spaceId), 'Boulangerie Martin - Instagram', [
            ['user' => $this->managed($this->owner), 'role' => NoteSpaceRoleEnum::Manager],
            ['user' => $this->managed($this->outsider), 'role' => NoteSpaceRoleEnum::Editor],
        ]);
        $this->entityManager->clear();
        $synced = $this->entityManager->find(NoteSpace::class, $spaceId);
        self::assertSame('Boulangerie Martin - Instagram', $synced?->getName());
        $members = $this->entityManager->getRepository(NoteSpaceMember::class)->findBy(['space' => $spaceId]);
        self::assertEqualsCanonicalizing(
            [$this->owner->getId(), $this->outsider->getId()],
            array_map(static fn (NoteSpaceMember $member): ?int => $member->getUser()->getId(), $members),
        );

        // What configured it disappears: to the trash, and no longer configured from elsewhere.
        self::assertInstanceOf(NoteSpace::class, $synced);
        $manager->releaseManaged($synced);
        $this->entityManager->clear();
        $released = $this->entityManager->find(NoteSpace::class, $spaceId);
        self::assertFalse($released?->isManaged());
        self::assertNotNull($released?->getDeletedAt());

        $admin = $this->user('admin');
        $this->managed($admin)->setRoles([UserRoleEnum::Admin->value]);
        $this->entityManager->flush();
        $this->client->loginUser($this->managed($admin), 'admin');
        $this->post('suite_notes_spaces_restore', [], ['id' => $spaceId]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $this->entityManager->remove($this->entityManager->getReference(NoteSpace::class, $spaceId));
        $this->entityManager->flush();
    }

    /** A manager does not close the space to "only me": they would shut themselves out. */
    public function testOnlyTheOwnerKeepsASpaceToThemselves(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $manager = $this->managed($this->editor);
        $member = $this->entityManager->getRepository(NoteSpaceMember::class)->findOneBy(['space' => $space->getId(), 'user' => $manager->getId()]);
        $member?->setRole(NoteSpaceRoleEnum::Manager);
        $this->entityManager->flush();

        $this->client->loginUser($manager, 'admin');
        $body = $this->post('suite_notes_spaces_update', ['name' => 'Fermé', 'access' => 'private'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('notes.markdown.spaces.errors.private_owner_only', $body['errors']['access'] ?? null);

        $this->client->loginUser($this->managed($this->owner), 'admin');
        $this->post('suite_notes_spaces_update', ['name' => 'Fermé', 'access' => 'private'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
    }

    /** The people list only serves someone who can add someone. */
    public function testThePeopleListIsForThoseWhoCanAddSomeone(): void
    {
        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_spaces_people'));
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['people']);

        $this->space(NoteSpaceAccessEnum::Members);
        $this->client->loginUser($this->managed($this->owner), 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_spaces_people'));
        self::assertNotSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['people']);
    }

    /** A public link never follows a wiki link out of its space. */
    public function testAPublicLinkNeverWalksOutOfItsSpace(): void
    {
        $personal = $this->personalSpaceOf($this->owner);
        $root = $this->note($this->owner, 'Présentation', $personal, content: 'Voir [[Tarifs internes]] et [[Annexe]]');
        $this->note($this->owner, 'Annexe', $personal, content: 'Publique');
        $this->note($this->owner, 'Tarifs internes', $this->space(NoteSpaceAccessEnum::Backoffice), content: 'Confidentiel');

        $walked = static::getContainer()->get(SharedNoteScope::class)->walk($root, true);
        $titles = array_map(static fn (MarkdownNote $one): string => (string) $one->getTitle(), $walked);

        self::assertContains('Annexe', $titles);
        self::assertNotContains('Tarifs internes', $titles);
    }

    /**
     * What a person wrote in a shared space outlives them; their personal
     * notebook goes with them.
     */
    public function testAnAuthorsDepartureLeavesTheirSharedNotesBehind(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);
        $shared = $this->note($this->editor, 'Mode opératoire', $space);
        $private = $this->note($this->editor, 'Brouillon', $this->personalSpaceOf($this->editor));

        $this->entityManager->clear();
        $this->entityManager->remove($this->entityManager->find(User::class, $this->editor->getId()));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $kept = $this->entityManager->find(MarkdownNote::class, $shared->getId());
        self::assertInstanceOf(MarkdownNote::class, $kept);
        self::assertNull($kept->getUser());
        self::assertNull($this->entityManager->find(MarkdownNote::class, $private->getId()));
    }

    /** Creating a shared space is a right; whoever creates it manages it. */
    public function testCreatingASpaceNeedsTheRight(): void
    {
        $this->client->loginUser($this->reader, 'admin');
        $this->post('suite_notes_spaces_create', ['name' => 'Refusé', 'access' => 'backoffice']);
        self::assertResponseStatusCodeSame(403);

        $creator = $this->user('createur', ['notes.markdown.use', 'notes.spaces.create']);
        $this->client->loginUser($creator, 'admin');
        $this->post('suite_notes_spaces_create', ['name' => '', 'access' => 'backoffice']);
        self::assertResponseStatusCodeSame(422);

        $body = $this->post('suite_notes_spaces_create', ['name' => 'Documentation', 'access' => 'members', 'defaultRole' => 'reader']);
        self::assertResponseIsSuccessful();
        self::assertSame('manager', $body['space']['role']);
        self::assertTrue($body['space']['isOwner']);
        self::assertSame('members', $body['space']['access']);

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_spaces_list'));
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($list['canCreate']);
        self::assertTrue($list['spaces'][0]['personal'], 'le sien en tête');
        self::assertContains($body['space']['id'], array_column($list['spaces'], 'id'));
    }

    /** The list says each person's role in each space. */
    public function testTheListCarriesEachRole(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_spaces_list'));
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $byId = array_column($list['spaces'], null, 'id');

        self::assertSame('reader', $byId[$space->getId()]['role']);
        self::assertFalse($byId[$space->getId()]['canWrite']);
        self::assertFalse($list['canCreate']);

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_spaces_list'));
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotContains($space->getId(), array_column($list['spaces'], 'id'));
    }

    /**
     * Only a manager configures the space and its members; a membership opens
     * the space, removing it closes it again.
     */
    public function testOnlyManagersChangeSettingsAndMembers(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $note = $this->note($this->owner, 'Devis', $space);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('suite_notes_spaces_update', ['name' => 'Détourné', 'access' => 'backoffice'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('suite_notes_spaces_members_set', ['userId' => $this->outsider->getId(), 'role' => 'editor'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_spaces_members_set', ['userId' => $this->outsider->getId(), 'role' => 'reader'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('suite_notes_spaces_members_set', ['userId' => $this->owner->getId(), 'role' => 'reader'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(422, 'le propriétaire ne se rétrograde pas');

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_spaces_show', ['id' => $space->getId()]));
        $shown = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains($this->outsider->getId(), array_column($shown['members'], 'userId'));

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_spaces_members_remove', [], ['id' => $space->getId(), 'userId' => $this->outsider->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);
    }

    /** One's personal space opens to nobody and cannot be removed. */
    public function testThePersonalSpaceStaysClosed(): void
    {
        $personal = $this->personalSpaceOf($this->owner);

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post('suite_notes_spaces_update', ['name' => 'Ouvert', 'access' => 'backoffice', 'color' => '#aa3300'], ['id' => $personal->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('private', $body['space']['access']);
        self::assertNull($body['space']['name']);
        self::assertSame('#aa3300', $body['space']['color']);

        $this->post('suite_notes_spaces_delete', [], ['id' => $personal->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('suite_notes_spaces_members_set', ['userId' => $this->reader->getId(), 'role' => 'reader'], ['id' => $personal->getId()]);
        self::assertResponseStatusCodeSame(404);
    }

    /** A removed space disappears for everyone, and its owner brings it back. */
    public function testARemovedSpaceComesBackWithItsNotes(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);
        $note = $this->note($this->owner, 'Procédure', $space);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_spaces_delete', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);
        $this->post('suite_notes_spaces_restore', [], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_spaces_restore', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();
    }

    /** Publishing is a separate right, on top of managing the space; never one's personal space. */
    public function testPublishingNeedsItsOwnRight(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_spaces_publish', ['published' => true], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->grantPublishing();
        $body = $this->post('suite_notes_spaces_publish', ['published' => true, 'slug' => 'Guide Équipe'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('guide-equipe', $body['space']['slug']);
        self::assertTrue($body['space']['published']);
        self::assertStringEndsWith('/p/guide-equipe', (string) $body['space']['publicUrl']);

        $this->post('suite_notes_spaces_publish', ['published' => true], ['id' => $this->personalSpaceOf($this->owner)->getId()]);
        self::assertResponseStatusCodeSame(404);

        $other = $this->space(NoteSpaceAccessEnum::Members);
        $this->post('suite_notes_spaces_publish', ['published' => true, 'slug' => 'guide-equipe'], ['id' => $other->getId()]);
        self::assertResponseStatusCodeSame(422, 'une adresse déjà prise');
    }

    /**
     * A published space is read without an account: its entry leads to its
     * first note, links stay inside the space, and search engines are kept
     * out by default.
     */
    public function testAPublishedSpaceIsReadWithoutAnAccount(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $folder = $this->folder($this->owner, 'Guides', $space);
        $first = $this->note($this->owner, 'Bienvenue', $space, $folder, 'Voir [[Tarifs]] et [[Secret]]');
        $tarifs = $this->note($this->owner, 'Tarifs', $space, $folder, 'Chiffres');
        $this->note($this->owner, 'Secret', $this->personalSpaceOf($this->owner), content: 'Privé');
        $slug = $this->publish($space);

        $this->client->restart();

        $this->client->request('GET', '/p/'.$slug);
        self::assertResponseRedirects('/p/'.$slug.'/'.$first->getId());

        $this->client->request('GET', '/p/'.$slug.'/'.$first->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow, noarchive', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow, noarchive">', $html);

        $props = $this->readProps($html);
        self::assertSame($tarifs->getId(), $props['titleIndex']['tarifs'] ?? null);
        self::assertArrayNotHasKey('secret', $props['titleIndex']);
        self::assertSame('/p/'.$slug.'/__id__', $props['readNotePath']);
        self::assertSame('', $props['favoritePath']);
        self::assertFalse($props['canEdit']);
        self::assertSame($tarifs->getId(), $props['next']['id'] ?? null);
    }

    /** Nothing else opens through a published space's address. */
    public function testNothingOutsideThePublishedSpaceLeaks(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $this->note($this->owner, 'Publique', $space);
        $trashed = $this->note($this->owner, 'Jetée', $space);
        $trashed->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();
        $elsewhere = $this->note($this->owner, 'Ailleurs', $this->space(NoteSpaceAccessEnum::Backoffice));
        $private = $this->note($this->owner, 'Journal', $this->personalSpaceOf($this->owner));
        $slug = $this->publish($space);

        $this->client->restart();

        foreach ([$trashed, $elsewhere, $private] as $note) {
            $this->client->request('GET', '/p/'.$slug.'/'.$note->getId());
            self::assertResponseStatusCodeSame(404);
        }

        $this->client->request('GET', '/p/'.$slug.'/'.$private->getId().'/images/abc.webp');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/p/inconnu');
        self::assertResponseStatusCodeSame(404);

        // Unpublished, it disappears as if it had never existed.
        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_spaces_publish', ['published' => false], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->client->restart();
        $this->client->request('GET', '/p/'.$slug);
        self::assertResponseStatusCodeSame(404);
    }

    /** A space that asks for it lets search engines index it. */
    public function testAnIndexableSpaceLetsEnginesIn(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $note = $this->note($this->owner, 'Documentation', $space);
        $slug = $this->publish($space, indexable: true);

        $this->client->restart();
        $this->client->request('GET', '/p/'.$slug.'/'.$note->getId());

        self::assertResponseIsSuccessful();
        // Symfony's debug mode sets `noindex` on every response, in dev as in
        // test; what matters here is that the page does not set its own.
        self::assertNotSame('noindex, nofollow, noarchive', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="index, follow">', (string) $this->client->getResponse()->getContent());
    }

    /** The owner gets the right to publish, and the space is published; returns its address. */
    private function publish(NoteSpaceInterface $space, bool $indexable = false): string
    {
        $this->grantPublishing();
        $body = $this->post('suite_notes_spaces_publish', ['published' => true, 'slug' => 'espace-'.$space->getId(), 'indexable' => $indexable], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();

        return (string) $body['space']['slug'];
    }

    private function grantPublishing(): void
    {
        $owner = $this->managed($this->owner);
        $owner->setPrivileges(['notes.markdown.use', 'notes.spaces.publish']);
        $this->entityManager->flush();
        $this->client->loginUser($owner, 'admin');
    }

    /**
     * A shared space, owned by `owner`, where `editor` is an editor and
     * `reader` a reader.
     */
    private function space(NoteSpaceAccessEnum $access): NoteSpaceInterface
    {
        $space = new NoteSpace();
        $space->setOwner($this->managed($this->owner))->setName('Espace '.bin2hex(random_bytes(3)))->setAccess($access)->setDefaultRole(NoteSpaceRoleEnum::Reader);
        $this->entityManager->persist($space);

        foreach ([[$this->editor, NoteSpaceRoleEnum::Editor], [$this->reader, NoteSpaceRoleEnum::Reader]] as [$user, $role]) {
            $member = new NoteSpaceMember();
            $member->setSpace($space)->setUser($this->managed($user))->setRole($role);
            $this->entityManager->persist($member);
        }

        $this->entityManager->flush();

        return $space;
    }

    private function note(User $author, string $title, NoteSpaceInterface $space, ?NoteFolder $folder = null, string $content = ''): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->managed($author))
            ->setSpace($this->managed($space))
            ->setTitle($title)
            ->setContent($content)
            ->setFolder(null === $folder ? null : $this->managed($folder));
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    private function folder(User $author, string $name, NoteSpaceInterface $space, ?NoteFolder $parent = null): NoteFolder
    {
        $folder = new NoteFolder();
        $folder->setUser($this->managed($author))
            ->setSpace($this->managed($space))
            ->setName($name)
            ->setParent(null === $parent ? null : $this->managed($parent));
        $this->entityManager->persist($folder);
        $this->entityManager->flush();

        return $folder;
    }

    /**
     * The entity as tracked by the entity manager.
     *
     * The kernel resets it after each request: what the test has held since
     * before is no longer tracked, and Doctrine would take it for something new.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function managed(object $entity): object
    {
        return $this->entityManager->getReference($entity::class, $entity->getId());
    }

    /** @param list<string> $privileges */
    private function user(string $name, array $privileges = ['notes.markdown.use']): User
    {
        $user = new User();
        $user->setEmail(sprintf('espaces-%s-%s@aurora.test', $name, bin2hex(random_bytes(4))))
            ->setName($name)
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges($privileges)
            ->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }

    private function upload(NoteSpaceInterface $space): void
    {
        $source = (string) tempnam(sys_get_temp_dir(), 'aurora-test-image-');
        file_put_contents($source, (string) base64_decode(self::PIXEL, true));

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_images_upload'),
            ['spaceId' => (string) $space->getId()],
            ['image' => new UploadedFile($source, 'pixel.png', 'image/png', null, true)],
        );
    }

    /** @return list<int> */
    private function listedIds(): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_list'));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): int => (int) $row['id'], $body['notes']);
    }

    /** @return array<string, mixed> */
    private function readProps(string $html): array
    {
        self::assertSame(1, preg_match('/data-symfony--ux-vue--vue-props-value="([^"]*readNotePath[^"]*)"/', $html, $match));

        return (array) json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, scalar> $parameters
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

    /** @return list<string> */
    private function entriesOf(string $path): array
    {
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));
        $entries = [];
        for ($entryIndex = 0; $entryIndex < $archive->numFiles; ++$entryIndex) {
            $entries[] = (string) $archive->getNameIndex($entryIndex);
        }
        $archive->close();

        return $entries;
    }
}
