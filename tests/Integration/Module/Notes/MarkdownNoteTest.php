<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Configuration\Setting\Service\SiteDateFormatter;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteArchive;
use Aurora\Module\Notes\Markdown\View\MarkdownNotesViewBuilder;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
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

        $other = $users->findOneBy(['type' => UserTypeEnum::Suite->value, 'email' => 'notes@aurora.test']);
        if (!$other instanceof User) {
            $other = new User();
            $other->setEmail('notes@aurora.test');
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

        $body = $this->post('suite_notes_markdown_create', [
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
            'suite_notes_markdown_show',
            ['id' => $note->getId()],
        ));
        self::assertResponseStatusCodeSame(404);
    }

    /** Writing to a note you do not own is refused, not silently applied. */
    public function testSomebodyElsesNoteCannotBeEdited(): void
    {
        $note = $this->note($this->owner, 'Intacte');

        $this->client->loginUser($this->other, 'admin');
        $this->post('suite_notes_markdown_update', ['title' => 'Piratée'], ['id' => $note->getId()]);

        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertInstanceOf(MarkdownNoteInterface::class, $fresh);
        self::assertSame('Intacte', $fresh->getTitle());
    }

    /**
     * Deleting a folder takes the notes inside it to the trash.
     *
     * The branch leaves whole and comes back whole, and a note keeps the
     * folder it was filed in throughout: that link is what a restore puts
     * back. Losing a page because you deleted the folder above it is not a
     * trade anybody offered, which is why the column says `ON DELETE SET
     * NULL` and why the deletion is a trashing rather than a delete.
     */
    public function testDeletingAFolderTakesItsNotesToTheTrash(): void
    {
        $folder = $this->folder($this->owner, 'Clients');
        $note = $this->note($this->owner, 'Enfant', $folder);
        $noteId = (int) $note->getId();
        $folderId = (int) $folder->getId();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_folders_delete', [], ['id' => $folderId]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $trashed = $this->entityManager->find(MarkdownNote::class, $noteId);
        self::assertInstanceOf(MarkdownNoteInterface::class, $trashed);
        self::assertTrue($trashed->isTrashed(), 'The note should have followed its folder.');
        self::assertSame($folderId, $trashed->getTrashedWithFolderId());
        self::assertNotNull($trashed->getFolder(), 'The filing has to survive for the restore to mean anything.');
    }

    public function testRestoringAFolderBringsItsNotesBack(): void
    {
        $folder = $this->folder($this->owner, 'Clients');
        $note = $this->note($this->owner, 'Enfant', $folder);
        $noteId = (int) $note->getId();
        $folderId = (int) $folder->getId();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_folders_delete', [], ['id' => $folderId]);
        $this->post('suite_notes_markdown_folders_restore', [], ['id' => $folderId]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $restored = $this->entityManager->find(MarkdownNote::class, $noteId);
        self::assertInstanceOf(MarkdownNoteInterface::class, $restored);
        self::assertFalse($restored->isTrashed());
        self::assertNull($restored->getTrashedWithFolderId());
    }

    public function testANoteTrashedOnItsOwnStaysThereWhenItsFolderComesBack(): void
    {
        $folder = $this->folder($this->owner, 'Clients');
        $note = $this->note($this->owner, 'Enfant', $folder);
        $noteId = (int) $note->getId();
        $folderId = (int) $folder->getId();

        $this->client->loginUser($this->owner, 'admin');
        // The note goes first, by hand: that is a decision of its own.
        $this->post('suite_notes_markdown_delete', [], ['id' => $noteId]);
        $this->post('suite_notes_markdown_folders_delete', [], ['id' => $folderId]);
        $this->post('suite_notes_markdown_folders_restore', [], ['id' => $folderId]);

        $this->entityManager->clear();
        $stillTrashed = $this->entityManager->find(MarkdownNote::class, $noteId);
        self::assertInstanceOf(MarkdownNoteInterface::class, $stillTrashed);
        self::assertTrue($stillTrashed->isTrashed(), 'A note deleted on purpose must not be resurrected by a branch restore.');
    }

    /**
     * A folder cannot be filed inside its own branch.
     *
     * The move is refused rather than applied: a cycle takes both branches
     * off every screen that builds a tree from the flat list, and no
     * interface can reach them afterwards to undo it.
     */
    public function testAFolderCannotBeMovedIntoItself(): void
    {
        $parent = $this->folder($this->owner, 'Parent');
        $child = $this->folder($this->owner, 'Enfant', $parent);

        $this->client->loginUser($this->owner, 'admin');
        $this->post(
            'suite_notes_markdown_folders_move',
            ['parentId' => $child->getId()],
            ['id' => $parent->getId()],
        );

        self::assertResponseStatusCodeSame(400);

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(NoteFolder::class, $parent->getId());
        self::assertInstanceOf(NoteFolder::class, $fresh);
        self::assertNull($fresh->getParent(), 'The refused move must leave the tree untouched.');
    }

    /**
     * A colour is kept, and a made-up colour is refused.
     *
     * It ends up in a style attribute: anything that is not `#rrggbb` is a
     * refusal, not a value cleaned up silently.
     */
    public function testAFolderKeepsItsColour(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $created = $this->post('suite_notes_markdown_folders_create', [
            'name' => 'Clients',
            'color' => '#22c55e',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('#22c55e', $created['folder']['color']);

        $id = (int) $created['folder']['id'];
        $this->created[] = [NoteFolder::class, $id];

        $this->post(
            'suite_notes_markdown_folders_update',
            ['name' => 'Clients', 'color' => 'rouge'],
            ['id' => $id],
        );

        self::assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(NoteFolder::class, $id);
        self::assertInstanceOf(NoteFolder::class, $fresh);
        self::assertSame('#22c55e', $fresh->getColor(), 'The refused colour must leave the folder alone.');
    }

    /**
     * A note's banner, and the page that shows only the note.
     *
     * The image stays with whoever hosts it: the note only keeps its address
     * and its credit, and **nothing enters the media library**.
     */
    public function testANoteKeepsItsCoverAndItsLook(): void
    {
        $note = $this->note($this->owner, 'Avec une image');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_update', [
            'title' => 'Avec une image',
            'content' => '',
            'coverUrl' => 'https://images.pexels.com/photos/1/photo.jpg',
            'coverCreditName' => 'Ada L.',
            'coverCreditUrl' => 'https://www.pexels.com/@ada',
            'coverPosition' => 20,
            'appearance' => 'sepia',
        ], ['id' => $note->getId()]);

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertInstanceOf(MarkdownNote::class, $fresh);
        self::assertSame('https://images.pexels.com/photos/1/photo.jpg', $fresh->getCoverUrl());
        self::assertSame('Ada L.', $fresh->getCoverCreditName());
        self::assertSame(20, $fresh->getCoverPosition());
        self::assertSame('sepia', $fresh->getAppearance()->value);
    }

    /**
     * Saving sends back the excerpt, by the same rule as the list: it is what
     * lets the library card follow the text without reloading the page.
     */
    public function testSavingANoteSendsBackItsFreshExcerpt(): void
    {
        $note = $this->note($this->owner, 'Extrait', content: 'Ancien texte.');

        $this->client->loginUser($this->owner, 'admin');
        $response = $this->post('suite_notes_markdown_update', [
            'title' => 'Extrait',
            'content' => "# Nouveau\n\n![photo](data:image/png;base64,AAAA)Un texte neuf.",
        ], ['id' => $note->getId()]);

        self::assertSame("# Nouveau\n\nUn texte neuf.", $response['note']['excerpt'] ?? null, 'the image is dropped, as in the list');
        self::assertNotNull($response['note']['updatedAt'] ?? null);
    }

    /** An address that is not an address is not written. */
    public function testACoverRefusesAnythingButAnHttpsAddress(): void
    {
        $note = $this->note($this->owner, 'Sans image');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_update', [
            'title' => 'Sans image',
            'content' => '',
            'coverUrl' => 'javascript:alert(1)',
        ], ['id' => $note->getId()]);

        self::assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertInstanceOf(MarkdownNote::class, $fresh);
        self::assertNull($fresh->getCoverUrl());
    }

    /**
     * The reader: a clean space, with the whole notebook on the left.
     *
     * It used to show a single note. It now carries its own tree - not the
     * back office menu, which reading has no need to drag along - and it
     * turns the pages in the tree's order. Only the person allowed to read
     * gets in: the account sets the scope, there is no token.
     */
    public function testTheReadingModeKeepsTheNotebookAroundTheNote(): void
    {
        $folder = $this->folder($this->owner, 'Lecture suivie');
        $first = $this->note($this->owner, 'Chapitre un', $folder, content: '# Un');
        $second = $this->note($this->owner, 'Chapitre deux', $folder, content: '# Deux');
        $second->setPosition(1);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate(
            'suite_notes_markdown_read',
            ['id' => $first->getId()],
        ));

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('notes/suite/markdown/NoteReadApp', $html);
        // A clean space: not the back office menu, its own tree instead.
        self::assertStringNotContainsString('core/suite/sidemenu/AppSidemenu', $html);

        $props = $this->readProps($html);
        $inTree = array_column($props['treeNotes'], 'id');
        self::assertContains($first->getId(), $inTree);
        self::assertContains($second->getId(), $inTree);
        self::assertTrue($props['canEdit']);
        self::assertSame('Lecture suivie', $props['breadcrumb'][0]['name'] ?? null);
        self::assertSame($second->getId(), $props['next']['id'] ?? null);

        $this->client->loginUser($this->other, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate(
            'suite_notes_markdown_read',
            ['id' => $first->getId()],
        ));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Entering the reader without a note: the address one bookmarks.
     * It opens the notebook's first note, or the library if it is empty.
     */
    public function testTheReaderHasAnEntryOfItsOwn(): void
    {
        $this->client->loginUser($this->other, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read_entry'));
        self::assertResponseRedirects($this->urlGenerator->generate('suite_notes_markdown'));

        $note = $this->note($this->other, 'Seule note');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read_entry'));
        self::assertResponseRedirects($this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
    }

    /**
     * Reordering a folder's subfolders leaves them inside it.
     *
     * The server only attached a folder to a parent present in the request;
     * dragging only sends the siblings, without their parent, and putting two
     * subfolders one before the other sent them back to the root.
     */
    public function testReorderingSubfoldersKeepsThemInTheirParent(): void
    {
        $parent = $this->folder($this->owner, 'Parent');
        $a = $this->folder($this->owner, 'A', $parent);
        $b = $this->folder($this->owner, 'B', $parent);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_folders_reorder', ['entries' => [
            ['id' => $b->getId(), 'parentId' => $parent->getId(), 'position' => 0],
            ['id' => $a->getId(), 'parentId' => $parent->getId(), 'position' => 1],
        ]]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $freshA = $this->entityManager->find(NoteFolder::class, $a->getId());
        $freshB = $this->entityManager->find(NoteFolder::class, $b->getId());

        self::assertSame($parent->getId(), $freshA?->getParent()?->getId());
        self::assertSame($parent->getId(), $freshB?->getParent()?->getId());
        self::assertSame(0, $freshB?->getPosition());
        self::assertSame(1, $freshA?->getPosition());
    }

    /**
     * A folder's folders and notes share a single order.
     *
     * A created note took the rank following the notes only: next to two
     * subfolders at ranks 0 and 1, it started again from 0 and slipped in
     * between them. It now lands after everything the folder holds.
     */
    public function testANewNoteComesAfterTheFoldersOfItsFolder(): void
    {
        $parent = $this->folder($this->owner, 'Parent');
        $this->folder($this->owner, 'A', $parent)->setPosition(0);
        $this->folder($this->owner, 'B', $parent)->setPosition(1);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post('suite_notes_markdown_create', ['title' => 'Tâches', 'folderId' => $parent->getId()]);
        self::assertResponseIsSuccessful();

        $note = $this->entityManager->find(MarkdownNote::class, $body['note']['id']);
        self::assertInstanceOf(MarkdownNote::class, $note);
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        self::assertSame(2, $note->getPosition());
    }

    /** A note that changes folder lands after everything the folder holds. */
    public function testAMovedNoteLandsAfterEverythingInItsNewFolder(): void
    {
        $target = $this->folder($this->owner, 'Cible');
        $this->folder($this->owner, 'Sous-dossier', $target)->setPosition(0);
        $this->note($this->owner, 'Déjà là', $target)->setPosition(1);
        $moving = $this->note($this->owner, 'Arrive');
        $moving->setPosition(0);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_move', ['folderId' => $target->getId()], ['id' => $moving->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(MarkdownNote::class, $moving->getId());

        self::assertSame($target->getId(), $fresh?->getFolder()?->getId());
        self::assertSame(2, $fresh?->getPosition());
    }

    /**
     * The reading order follows the tree's, folders and notes mixed: a note
     * placed before a folder is read before that folder's notes. It is the
     * order of previous / next and of the public page.
     */
    public function testTheReadingOrderMixesFoldersAndNotes(): void
    {
        $space = new NoteSpace();
        $space->setOwner($this->owner)->setName('Ordre')->setAccess(NoteSpaceAccessEnum::Backoffice);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->created[] = [NoteSpace::class, (int) $space->getId()];

        $folder = new NoteFolder();
        $folder->setUser($this->owner)->setSpace($space)->setName('Dossier')->setPosition(1);
        $this->entityManager->persist($folder);
        $this->entityManager->flush();
        $this->created[] = [NoteFolder::class, (int) $folder->getId()];

        $before = $this->note($this->owner, 'Avant le dossier');
        $before->setSpace($space)->setPosition(0);
        $inside = $this->note($this->owner, 'Dans le dossier', $folder);
        $inside->setPosition(0);
        $this->entityManager->flush();

        $view = static::getContainer()->get(MarkdownNotesViewBuilder::class);

        self::assertSame($before->getId(), $view->firstInSpace($space));

        $before->setPosition(2);
        $this->entityManager->flush();

        self::assertSame($inside->getId(), $view->firstInSpace($space));
    }

    /**
     * Duplicating places the copy right under the original, like Craft and
     * Notion: the following siblings move down one rank, folders included.
     */
    public function testDuplicatingPutsTheCopyRightUnderTheOriginal(): void
    {
        $folder = $this->folder($this->owner, 'Briefs');
        $original = $this->note($this->owner, 'Brief', $folder, '# Objectif');
        $original->setPosition(0)->setTags(['client']);
        $sub = $this->folder($this->owner, 'Archives', $folder);
        $sub->setPosition(1);
        $after = $this->note($this->owner, 'Après', $folder);
        $after->setPosition(2);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post('suite_notes_markdown_duplicate', [], ['id' => $original->getId()]);
        self::assertResponseIsSuccessful();
        $this->created[] = [MarkdownNote::class, (int) $body['note']['id']];

        $this->entityManager->clear();
        $copy = $this->entityManager->find(MarkdownNote::class, $body['note']['id']);

        self::assertSame('Copie de Brief', $copy?->getTitle());
        self::assertSame('# Objectif', $copy?->getContent());
        self::assertSame(['client'], $copy?->getTags());
        self::assertSame($folder->getId(), $copy?->getFolder()?->getId());
        self::assertSame(1, $copy?->getPosition());
        self::assertSame(2, $this->entityManager->find(NoteFolder::class, $sub->getId())?->getPosition());
        self::assertSame(3, $this->entityManager->find(MarkdownNote::class, $after->getId())?->getPosition());
    }

    /** A note one cannot write is not duplicated. */
    public function testDuplicatingAnotherPersonsNoteIsRefused(): void
    {
        $note = $this->note($this->owner, 'Personnel');

        $this->client->loginUser($this->other, 'admin');
        $this->post('suite_notes_markdown_duplicate', [], ['id' => $note->getId()]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A template becomes a new note, placed last where it is created, with
     * today's date instead of its placeholder. The template stays intact.
     */
    public function testATemplateBecomesANewNoteWithTodaysDate(): void
    {
        $template = $this->note($this->owner, 'Compte rendu', null, "# Réunion du {{date}}\n\n- Présents :");
        $target = $this->folder($this->owner, 'Réunions');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_template', ['template' => true], ['id' => $template->getId()]);
        self::assertResponseIsSuccessful();

        $body = $this->post('suite_notes_markdown_from_template', ['folderId' => $target->getId(), 'title' => 'Point Lumen'], ['id' => $template->getId()]);
        self::assertResponseIsSuccessful();
        $this->created[] = [MarkdownNote::class, (int) $body['note']['id']];

        $this->entityManager->clear();
        $note = $this->entityManager->find(MarkdownNote::class, $body['note']['id']);
        $today = static::getContainer()->get(SiteDateFormatter::class)->date(new DateTimeImmutable(), 'fr');

        self::assertSame('Point Lumen', $note?->getTitle());
        self::assertSame("# Réunion du {$today}\n\n- Présents :", $note?->getContent());
        self::assertSame($target->getId(), $note?->getFolder()?->getId());
        self::assertFalse($note?->isTemplate());
        self::assertTrue($this->entityManager->find(MarkdownNote::class, $template->getId())?->isTemplate());
        self::assertStringContainsString('{{date}}', (string) $this->entityManager->find(MarkdownNote::class, $template->getId())?->getContent());
    }

    /** Only a note marked as a template serves as a template. */
    public function testAnOrdinaryNoteIsNotATemplate(): void
    {
        $note = $this->note($this->owner, 'Ordinaire');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_from_template', [], ['id' => $note->getId()]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The history: an edit keeps the state it replaces, but not on every
     * keystroke. Two saves a few seconds apart make only one version (the
     * interval from the settings).
     */
    public function testEditingANoteKeepsThePreviousVersionOncePerInterval(): void
    {
        $note = $this->note($this->owner, 'Brief', null, 'Version 1');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_update', ['title' => 'Brief', 'content' => 'Version 2'], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('suite_notes_markdown_update', ['title' => 'Brief', 'content' => 'Version 3'], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => $note->getId()]));
        $revisions = json_decode((string) $this->client->getResponse()->getContent(), true)['revisions'];

        self::assertCount(1, $revisions);

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revision', ['id' => $note->getId(), 'revisionId' => $revisions[0]['id']]));
        self::assertSame('Version 1', json_decode((string) $this->client->getResponse()->getContent(), true)['revision']['content']);
    }

    /** Restoring puts a version back and keeps the current state first: nothing is lost. */
    public function testRestoringAVersionKeepsTheCurrentStateFirst(): void
    {
        $note = $this->note($this->owner, 'Brief', null, 'Ancien texte');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_update', ['title' => 'Brief', 'content' => 'Nouveau texte'], ['id' => $note->getId()]);

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => $note->getId()]));
        $old = json_decode((string) $this->client->getResponse()->getContent(), true)['revisions'][0]['id'];

        $body = $this->post('suite_notes_markdown_revision_restore', [], ['id' => $note->getId(), 'revisionId' => $old]);
        self::assertResponseIsSuccessful();
        self::assertSame('Ancien texte', $body['note']['content']);

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => $note->getId()]));
        $revisions = json_decode((string) $this->client->getResponse()->getContent(), true)['revisions'];
        self::assertCount(2, $revisions);

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revision', ['id' => $note->getId(), 'revisionId' => $revisions[0]['id']]));
        self::assertSame('Nouveau texte', json_decode((string) $this->client->getResponse()->getContent(), true)['revision']['content']);
    }

    /**
     * An image removed from the text stays as long as a past version shows it:
     * otherwise restoring that version would give a broken image.
     */
    public function testAnImageOnlyAnOldVersionShowsIsKept(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $source = (string) tempnam(sys_get_temp_dir(), 'aurora-test-image-');
        file_put_contents($source, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_images_upload'),
            files: ['image' => new UploadedFile($source, 'pixel.png', 'image/png', null, true)],
        );
        $url = json_decode((string) $this->client->getResponse()->getContent(), true)['url'];

        $note = $this->post('suite_notes_markdown_create', ['title' => 'Illustrée', 'content' => sprintf('![Un pixel](%s)', $url)]);
        $this->created[] = [MarkdownNote::class, (int) $note['note']['id']];

        $this->post('suite_notes_markdown_update', ['title' => 'Illustrée', 'content' => 'Sans image'], ['id' => $note['note']['id']]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    /** A note's history is read with the note, not without it. */
    public function testAnotherPersonCannotReadTheHistory(): void
    {
        $note = $this->note($this->owner, 'Personnel', null, 'Secret');

        $this->client->loginUser($this->other, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => $note->getId()]));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A folder is not placed under its own child, even when the child is not
     * in the request: the loop is also read from the parents already stored.
     */
    public function testReorderRefusesACycleThroughStoredParents(): void
    {
        $top = $this->folder($this->owner, 'Haut');
        $child = $this->folder($this->owner, 'Enfant', $top);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('suite_notes_markdown_folders_reorder', ['entries' => [
            ['id' => $top->getId(), 'parentId' => $child->getId(), 'position' => 0],
        ]]);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(NoteFolder::class, $top->getId())?->getParent());
    }

    /**
     * A save started from an outdated version is refused.
     *
     * The editor saves on its own: without this check, with two people on the
     * same note, the last one typing wiped out the other without anyone
     * knowing.
     */
    public function testASaveFromAnOutdatedVersionIsRefused(): void
    {
        $note = $this->note($this->owner, 'Versionnée', content: 'v1');
        $this->client->loginUser($this->owner, 'admin');

        $saved = $this->post('suite_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'v2', 'version' => 1], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $saved['note']['version']);

        // Started from version 1 while the note is at 2: refused.
        $this->post('suite_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'écrasé', 'version' => 1], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['conflict']);

        // Overwriting knowingly goes through, and so does a call without a version.
        $this->post('suite_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'forcé', 'version' => 1, 'force' => true], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('suite_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'sans version'], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('sans version', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** Somebody else's folder is neither listed nor reachable. */
    public function testSomebodyElsesFolderIsNotListed(): void
    {
        $folder = $this->folder($this->owner, 'Privé');

        $this->client->loginUser($this->other, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_folders_list'));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $body['folders']);

        self::assertNotContains((int) $folder->getId(), $ids);

        $this->client->request('GET', $this->urlGenerator->generate(
            'suite_notes_markdown_folder',
            ['id' => $folder->getId()],
        ));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The notebook has a page of its own, and it no longer redirects elsewhere.
     *
     * The address redirected to the first note, for lack of a screen to show:
     * opening the module landed on a text instead of showing what is there.
     */
    public function testTheLibraryIsAPageOfItsOwn(): void
    {
        $this->folder($this->owner, 'Clients');
        $this->note($this->owner, 'Une note');

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown'));

        self::assertResponseIsSuccessful();
    }

    /** A folder is an address, with its breadcrumb resolved on the server. */
    public function testAFolderHasItsOwnAddress(): void
    {
        $parent = $this->folder($this->owner, 'Clients');
        $child = $this->folder($this->owner, 'Studio Lumen', $parent);

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate(
            'suite_notes_markdown_folder',
            ['id' => $child->getId()],
        ));

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // The breadcrumb starts from the root: without it, a reload would show
        // the root while the browser recalculates.
        self::assertStringContainsString('Studio Lumen', $html);
        self::assertStringContainsString('Clients', $html);

        // And above all: the page tells the component which folder it is.
        // Without this prop, the library opens on the root while the address
        // names a folder - which is what Axel saw.
        self::assertMatchesRegularExpression(
            '/&quot;folderId&quot;:\s*'.$child->getId().'\b/',
            $html,
            'la page du dossier doit transmettre son identifiant au composant',
        );
    }

    /** A filed note carries its folder in the list, not a parent. */
    public function testTheFlatListCarriesTheFolder(): void
    {
        $folder = $this->folder($this->owner, 'Clients');
        $note = $this->note($this->owner, 'Devis', $folder);

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_list'));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $row = current(array_filter(
            $body['notes'],
            static fn (array $one): bool => (int) $one['id'] === $note->getId(),
        ));

        self::assertIsArray($row);
        self::assertSame($folder->getId(), (int) $row['folderId']);
    }

    /**
     * The excerpt the grid shows, computed on the server.
     *
     * It costs one decryption per note: eight more milliseconds on a notebook
     * of five hundred notes, measured, which is the price of a card that shows
     * something other than a title. **It goes out as Markdown**, not
     * flattened: the thumbnail renders it small, and seeing a heading and a
     * list is how a note is recognised.
     */
    public function testTheListCarriesAnExcerpt(): void
    {
        $note = $this->note($this->owner, 'Avec du texte', content: "# Titre\n\n- une liste\n- deux");

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_list'));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $row = current(array_filter(
            $body['notes'],
            static fn (array $one): bool => (int) $one['id'] === $note->getId(),
        ));

        self::assertIsArray($row);
        self::assertSame("# Titre\n\n- une liste\n- deux", $row['excerpt']);
    }

    /**
     * An image does not go into the excerpt, and a cut code block is closed
     * again.
     *
     * A single `data:` image would weigh more than the whole list, and a
     * missing fence would make the end of the thumbnail pass for code.
     */
    public function testTheExcerptDropsImagesAndClosesWhatItCuts(): void
    {
        $note = $this->note(
            $this->owner,
            'Avec une image',
            content: "![vue](data:image/png;base64,AAAA)\n\nLe texte.\n\n```php\necho 1;",
        );

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_list'));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $row = current(array_filter(
            $body['notes'],
            static fn (array $one): bool => (int) $one['id'] === $note->getId(),
        ));

        self::assertIsArray($row);
        self::assertStringNotContainsString('data:image', (string) $row['excerpt']);
        self::assertSame(2, mb_substr_count((string) $row['excerpt'], '```'));
    }

    /**
     * Pinning a note, and unpinning it.
     *
     * The menu panel opens on this: a notebook has three or four places one
     * goes back to every day, and looking for them in the tree every time is
     * the chore favourites remove.
     */
    public function testANoteCanBePinnedAndUnpinned(): void
    {
        $note = $this->note($this->owner, 'À portée de main');

        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post('suite_notes_markdown_favorite', [], ['id' => $note->getId()]);
        self::assertTrue($body['favorite']);
        self::assertNotNull($this->listedRow((int) $note->getId())['favoritedAt']);

        // The reader knows it too: its star lights up.
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_read', ['id' => $note->getId()]));
        self::assertTrue($this->readProps((string) $this->client->getResponse()->getContent())['favorited']);

        $body = $this->post('suite_notes_markdown_favorite', [], ['id' => $note->getId()]);
        self::assertFalse($body['favorite']);
        self::assertNull($this->listedRow((int) $note->getId())['favoritedAt']);
    }

    /**
     * Favourites belong to the person: one pins what one can read, for
     * oneself alone, and never what one cannot see.
     */
    public function testFavoritesBelongToWhoPinsThem(): void
    {
        $folder = $this->folder($this->owner, 'Clients');

        $this->client->loginUser($this->other, 'admin');
        $this->post('suite_notes_markdown_folders_favorite', [], ['id' => $folder->getId()]);
        self::assertResponseStatusCodeSame(404);

        // In a space open to everyone, each person pins for themselves.
        $space = new NoteSpace();
        $space->setOwner($this->owner)->setName('Équipe')->setAccess(NoteSpaceAccessEnum::Backoffice);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->created[] = [NoteSpace::class, (int) $space->getId()];
        $shared = $this->note($this->owner, 'Procédure');
        $shared->setSpace($space);
        $this->entityManager->flush();

        $this->client->loginUser($this->other, 'admin');
        $body = $this->post('suite_notes_markdown_favorite', [], ['id' => $shared->getId()]);
        self::assertTrue($body['favorite']);
        self::assertNotNull($this->listedRow((int) $shared->getId())['favoritedAt']);

        $this->client->loginUser($this->owner, 'admin');
        self::assertNull($this->listedRow((int) $shared->getId())['favoritedAt']);
        $body = $this->post('suite_notes_markdown_folders_favorite', [], ['id' => $folder->getId()]);
        self::assertTrue($body['favorite']);
    }

    /** @return array<string, mixed> */
    private function listedRow(int $id): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_list'));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $row = current(array_filter($body['notes'], static fn (array $one): bool => (int) $one['id'] === $id));
        self::assertIsArray($row);

        return $row;
    }

    /**
     * The list's dates go out as strings, not objects.
     *
     * Doctrine's array hydration returns `DateTimeImmutable` objects, which
     * `json_encode` writes as `{date, timezone_type, timezone}`. The browser
     * does not see a date in that: `Intl` throws, and the exception takes the
     * whole component down - the page becomes an empty frame. Nobody saw it
     * as long as no screen displayed these dates.
     */
    public function testTheFlatListSendsDatesAsStrings(): void
    {
        $note = $this->note($this->owner, 'Datée');

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_list'));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $row = current(array_filter(
            $body['notes'],
            static fn (array $one): bool => (int) $one['id'] === $note->getId(),
        ));

        self::assertIsArray($row);
        self::assertIsString($row['updatedAt'], 'une date de liste est une chaîne ISO');
        self::assertIsString($row['createdAt']);
        self::assertNotFalse(strtotime($row['updatedAt']), 'et cette chaîne se relit');
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

    /** The props passed to the reading page. */
    private function readProps(string $html): array
    {
        self::assertSame(1, preg_match('/data-symfony--ux-vue--vue-props-value="([^"]*readNotePath[^"]*)"/', $html, $match));

        return (array) json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
    }

    private function note(User $user, string $title, ?NoteFolder $folder = null, string $content = ''): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($user);
        $note->setSpace($folder?->getSpace() ?? $this->personalSpaceOf($user));
        $note->setTitle($title);
        $note->setContent($content);
        if (null !== $folder) {
            $note->setFolder($folder);
        }
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        return $note;
    }

    private function folder(User $user, string $name, ?NoteFolder $parent = null): NoteFolder
    {
        $folder = new NoteFolder();
        $folder->setUser($user);
        $folder->setSpace($parent?->getSpace() ?? $this->personalSpaceOf($user));
        $folder->setName($name);
        $folder->setParent($parent);
        $this->entityManager->persist($folder);
        $this->entityManager->flush();
        $this->created[] = [NoteFolder::class, (int) $folder->getId()];

        return $folder;
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
     * @return array<string, mixed>
     */

    /**
     * **The round trip, which is the only proof an export is worth anything.**.
     *
     * A notebook exported then imported again must give back the same tree,
     * the same titles and the same tags. Without that, the export is a pile of
     * files, not a way out.
     */
    public function testTheNotebookSurvivesAnExportAndAnImport(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $folder = $this->post('suite_notes_markdown_folders_create', ['name' => 'Clients']);
        $folderId = $folder['folder']['id'];
        $this->created[] = [NoteFolder::class, (int) $folderId];

        $child = $this->post('suite_notes_markdown_create', [
            'title' => 'Studio Lumen',
            'content' => 'Photo, en cours.',
            'folderId' => $folderId,
        ]);
        $this->created[] = [MarkdownNote::class, (int) $child['note']['id']];

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_export'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // The route returns a file, not a body: `getContent()` answers false
        // there. And this one is deleted once sent, on purpose. So the archive
        // examined is the one the service builds again, and the route is
        // judged on what it promises - a file, and a 200.
        self::assertInstanceOf(BinaryFileResponse::class, $this->client->getResponse());

        $path = static::getContainer()->get(MarkdownNoteArchive::class)->zipFor($this->owner);

        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));

        // The tree is in the paths: a filed note is a file in the directory
        // named after its folder.
        $entries = [];
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $entries[] = (string) $archive->getNameIndex($i);
        }
        $archive->close();

        self::assertContains('Clients/', $entries, 'le dossier est déclaré, même vide');
        self::assertContains('Clients/Studio Lumen.md', $entries);

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_import'),
            files: ['files' => [new UploadedFile($path, 'notes.zip', 'application/zip', null, true)]],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        // A folder and a note: the directory walked through counts for the
        // folder it is, only once.
        self::assertSame(2, $body['created']);

        foreach ($this->notes() as $note) {
            $this->created[] = [MarkdownNote::class, (int) $note->getId()];
        }

        foreach ($this->folders() as $one) {
            $this->created[] = [NoteFolder::class, (int) $one->getId()];
        }

        // Nothing is overwritten: the original and the imported one coexist.
        self::assertSame(
            2,
            count(array_filter($this->folders(), static fn (NoteFolder $one): bool => 'Clients' === $one->getName())),
        );

        $imported = null;
        foreach ($this->notes() as $note) {
            if ('Studio Lumen' === $note->getTitle() && $note->getId() !== $child['note']['id']) {
                $imported = $note;
            }
        }

        self::assertInstanceOf(MarkdownNoteInterface::class, $imported, 'la note est revenue');
        self::assertSame('Clients', $imported->getFolder()?->getName(), 'et dans son dossier');
    }

    /** Tags travel in the front matter, and come back as tags. */
    public function testTagsSurviveTheRoundTrip(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $note = $this->post('suite_notes_markdown_create', [
            'title' => 'Avec étiquettes',
            'content' => 'Du texte.',
            'tags' => ['photo', 'méthode'],
        ]);
        $this->created[] = [MarkdownNote::class, (int) $note['note']['id']];

        $this->client->request(
            'GET',
            $this->urlGenerator->generate('suite_notes_markdown_export_one', ['id' => $note['note']['id']]),
        );

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringStartsWith("---\ntags: [photo, méthode]\n---", $body);

        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-note-test-');
        file_put_contents($path, $body);

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_import'),
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
        // The front matter did not stay in the text.
        self::assertSame('Du texte.', mb_trim((string) $imported->getContent()));
    }

    /** @return list<NoteFolder> */
    private function folders(): array
    {
        $this->entityManager->clear();

        return static::getContainer()
            ->get(NoteFolderRepository::class)
            ->findAllForUser($this->owner);
    }

    /** @return list<MarkdownNoteInterface> */
    private function notes(): array
    {
        $this->entityManager->clear();

        return static::getContainer()
            ->get(MarkdownNoteRepository::class)
            ->findAllWithContentForUser($this->owner);
    }

    /**
     * An archive is self-contained: the images go with the text.
     *
     * That is what was missing. The markdown carried the back office address,
     * so an exported notebook opened in Obsidian with its images as broken
     * icons - or worse, as login prompts. The test does the way out **and**
     * the way back, because the half that matters is the one where the
     * archive comes back: a re-imported image must become an image of the
     * person importing again, not a link to someone else's file.
     */
    public function testAnExportCarriesItsImagesAndAnImportTakesThemBack(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        // A one-pixel PNG, written here rather than read from a file: the test
        // depends on no resource next to it.
        $pixel = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        $source = (string) tempnam(sys_get_temp_dir(), 'aurora-test-image-');
        file_put_contents($source, $pixel);

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_images_upload'),
            files: ['image' => new UploadedFile($source, 'pixel.png', 'image/png', null, true)],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $upload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $url = $upload['url'];

        // The note is filed in a folder, so the relative path has one level to
        // climb: that is where the export went wrong most easily.
        $folder = $this->post('suite_notes_markdown_folders_create', ['name' => 'Illustré']);
        $this->created[] = [NoteFolder::class, (int) $folder['folder']['id']];

        $note = $this->post('suite_notes_markdown_create', [
            'title' => 'Une note illustrée',
            'content' => sprintf('Voici le pixel :\n\n![Un pixel](%s)\n', $url),
            'folderId' => $folder['folder']['id'],
        ]);
        $this->created[] = [MarkdownNote::class, (int) $note['note']['id']];

        $path = static::getContainer()->get(MarkdownNoteArchive::class)->zipFor($this->owner);

        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));

        $entries = [];
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $entries[] = (string) $archive->getNameIndex($i);
        }

        $exported = (string) $archive->getFromName('Illustré/Une note illustrée.md');
        $archive->close();

        self::assertContains('_images/'.$upload['filename'], $entries, "l'image est dans l'archive");
        self::assertStringContainsString(
            '../_images/'.$upload['filename'],
            $exported,
            'la note remonte d\'un cran, puisqu\'elle est dans un dossier',
        );
        self::assertStringNotContainsString(
            '/suite/notes/markdown/images/',
            $exported,
            "plus aucune adresse du back-office dans l'archive",
        );

        // Le retour.
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_import'),
            files: ['files' => [new UploadedFile($path, 'notes.zip', 'application/zip', null, true)]],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->notes() as $one) {
            $this->created[] = [MarkdownNote::class, (int) $one->getId()];
        }

        foreach ($this->folders() as $one) {
            $this->created[] = [NoteFolder::class, (int) $one->getId()];
        }

        $reimported = null;
        foreach ($this->notes() as $one) {
            if ($one->getId() !== (int) $note['note']['id'] && 'Une note illustrée' === (string) $one->getTitle()) {
                $reimported = $one;
            }
        }

        self::assertNotNull($reimported, 'la note importée existe à côté de l\'originale');

        $contenu = (string) $reimported->getContent();

        self::assertStringContainsString(
            '/suite/notes/markdown/images/',
            $contenu,
            "le chemin relatif est redevenu une adresse servie par l'application",
        );
        self::assertStringNotContainsString('_images/', $contenu);
        self::assertStringNotContainsString(
            $upload['filename'],
            $contenu,
            "l'image importée est une nouvelle image, pas un lien vers l'ancienne",
        );

        unlink($source);
        unlink($path);
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
