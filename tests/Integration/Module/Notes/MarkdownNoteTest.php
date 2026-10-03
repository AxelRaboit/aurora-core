<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

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
        $this->post('backend_notes_markdown_folders_delete', [], ['id' => $folderId]);
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
        $this->post('backend_notes_markdown_folders_delete', [], ['id' => $folderId]);
        $this->post('backend_notes_markdown_folders_restore', [], ['id' => $folderId]);
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
        $this->post('backend_notes_markdown_delete', [], ['id' => $noteId]);
        $this->post('backend_notes_markdown_folders_delete', [], ['id' => $folderId]);
        $this->post('backend_notes_markdown_folders_restore', [], ['id' => $folderId]);

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
            'backend_notes_markdown_folders_move',
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
     * Une couleur se garde, et une couleur inventée se refuse.
     *
     * Elle finit dans un attribut de style : tout ce qui n'est pas
     * `#rrggbb` est un refus, pas une valeur qu'on nettoie en silence.
     */
    public function testAFolderKeepsItsColour(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        $created = $this->post('backend_notes_markdown_folders_create', [
            'name' => 'Clients',
            'color' => '#22c55e',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('#22c55e', $created['folder']['color']);

        $id = (int) $created['folder']['id'];
        $this->created[] = [NoteFolder::class, $id];

        $this->post(
            'backend_notes_markdown_folders_update',
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
     * Le bandeau d'une note, et la page qui ne montre qu'elle.
     *
     * L'image reste chez celui qui l'héberge : la note n'en garde que
     * l'adresse et le crédit, et **rien n'entre dans la médiathèque**.
     */
    public function testANoteKeepsItsCoverAndItsLook(): void
    {
        $note = $this->note($this->owner, 'Avec une image');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_update', [
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
     * L'enregistrement renvoie l'extrait, par la même règle que la liste :
     * c'est ce qui permet à la carte de la bibliothèque de suivre le texte
     * sans recharger la page.
     */
    public function testSavingANoteSendsBackItsFreshExcerpt(): void
    {
        $note = $this->note($this->owner, 'Extrait', content: 'Ancien texte.');

        $this->client->loginUser($this->owner, 'admin');
        $response = $this->post('backend_notes_markdown_update', [
            'title' => 'Extrait',
            'content' => "# Nouveau\n\n![photo](data:image/png;base64,AAAA)Un texte neuf.",
        ], ['id' => $note->getId()]);

        self::assertSame("# Nouveau\n\nUn texte neuf.", $response['note']['excerpt'] ?? null, 'the image is dropped, as in the list');
        self::assertNotNull($response['note']['updatedAt'] ?? null);
    }

    /** Une adresse qui n'est pas une adresse ne s'écrit pas. */
    public function testACoverRefusesAnythingButAnHttpsAddress(): void
    {
        $note = $this->note($this->owner, 'Sans image');

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_update', [
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
     * Le lecteur : un espace épuré, avec tout le carnet à gauche.
     *
     * Il montrait une note seule. Il porte maintenant sa propre
     * arborescence - pas le menu du back-office, que la lecture n'a pas à
     * traîner - et il tourne les pages dans l'ordre de l'arborescence. Seule la personne qui a le droit de lire y
     * entre : c'est le compte qui fait la portée, il n'y a pas de jeton.
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
            'backend_notes_markdown_read',
            ['id' => $first->getId()],
        ));

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('notes/backend/markdown/NoteReadApp', $html);
        // Un espace épuré : pas le menu du back-office, sa propre
        // arborescence à la place.
        self::assertStringNotContainsString('core/backend/sidemenu/AppSidemenu', $html);

        $props = $this->readProps($html);
        $inTree = array_column($props['treeNotes'], 'id');
        self::assertContains($first->getId(), $inTree);
        self::assertContains($second->getId(), $inTree);
        self::assertTrue($props['canEdit']);
        self::assertSame('Lecture suivie', $props['breadcrumb'][0]['name'] ?? null);
        self::assertSame($second->getId(), $props['next']['id'] ?? null);

        $this->client->loginUser($this->other, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate(
            'backend_notes_markdown_read',
            ['id' => $first->getId()],
        ));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Entrer dans le lecteur sans note : l'adresse qu'on met en favori.
     * Elle ouvre la première note du carnet, ou la bibliothèque s'il est vide.
     */
    public function testTheReaderHasAnEntryOfItsOwn(): void
    {
        $this->client->loginUser($this->other, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read_entry'));
        self::assertResponseRedirects($this->urlGenerator->generate('backend_notes_markdown'));

        $note = $this->note($this->other, 'Seule note');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read_entry'));
        self::assertResponseRedirects($this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
    }

    /**
     * Réordonner les sous-dossiers d'un dossier les laisse dedans.
     *
     * Le serveur ne rattachait un dossier qu'à un parent présent dans la
     * requête ; le glisser n'envoie que les frères, sans leur parent, et
     * ranger deux sous-dossiers l'un avant l'autre les renvoyait à la racine.
     */
    public function testReorderingSubfoldersKeepsThemInTheirParent(): void
    {
        $parent = $this->folder($this->owner, 'Parent');
        $a = $this->folder($this->owner, 'A', $parent);
        $b = $this->folder($this->owner, 'B', $parent);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_folders_reorder', ['entries' => [
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
     * Dossiers et notes d'un dossier partagent un seul ordre.
     *
     * Une note créée prenait le rang qui suivait les notes seulement : à côté
     * de deux sous-dossiers de rangs 0 et 1, elle repartait de 0 et se glissait
     * entre eux. Elle arrive maintenant après tout ce que le dossier contient.
     */
    public function testANewNoteComesAfterTheFoldersOfItsFolder(): void
    {
        $parent = $this->folder($this->owner, 'Parent');
        $this->folder($this->owner, 'A', $parent)->setPosition(0);
        $this->folder($this->owner, 'B', $parent)->setPosition(1);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post('backend_notes_markdown_create', ['title' => 'Tâches', 'folderId' => $parent->getId()]);
        self::assertResponseIsSuccessful();

        $note = $this->entityManager->find(MarkdownNote::class, $body['note']['id']);
        self::assertInstanceOf(MarkdownNote::class, $note);
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        self::assertSame(2, $note->getPosition());
    }

    /** Une note qui change de dossier arrive après tout ce qu'il contient. */
    public function testAMovedNoteLandsAfterEverythingInItsNewFolder(): void
    {
        $target = $this->folder($this->owner, 'Cible');
        $this->folder($this->owner, 'Sous-dossier', $target)->setPosition(0);
        $this->note($this->owner, 'Déjà là', $target)->setPosition(1);
        $moving = $this->note($this->owner, 'Arrive');
        $moving->setPosition(0);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_move', ['folderId' => $target->getId()], ['id' => $moving->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(MarkdownNote::class, $moving->getId());

        self::assertSame($target->getId(), $fresh?->getFolder()?->getId());
        self::assertSame(2, $fresh?->getPosition());
    }

    /**
     * L'ordre de lecture suit celui de l'arborescence, dossiers et notes
     * mêlés : une note rangée avant un dossier se lit avant les notes de ce
     * dossier. C'est l'ordre du précédent / suivant et de la page publique.
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
     * Un dossier ne se range pas sous son propre enfant, même quand l'enfant
     * n'est pas dans la requête : la boucle se lit aussi dans les parents
     * déjà enregistrés.
     */
    public function testReorderRefusesACycleThroughStoredParents(): void
    {
        $top = $this->folder($this->owner, 'Haut');
        $child = $this->folder($this->owner, 'Enfant', $top);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_markdown_folders_reorder', ['entries' => [
            ['id' => $top->getId(), 'parentId' => $child->getId(), 'position' => 0],
        ]]);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(NoteFolder::class, $top->getId())?->getParent());
    }

    /**
     * Un enregistrement parti d'une version dépassée est refusé.
     *
     * L'éditeur enregistre tout seul : sans ce contrôle, à deux sur une même
     * note, le dernier qui tapait effaçait l'autre sans que personne le sache.
     */
    public function testASaveFromAnOutdatedVersionIsRefused(): void
    {
        $note = $this->note($this->owner, 'Versionnée', content: 'v1');
        $this->client->loginUser($this->owner, 'admin');

        $saved = $this->post('backend_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'v2', 'version' => 1], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $saved['note']['version']);

        // Parti de la version 1 alors que la note est en 2 : refusé.
        $this->post('backend_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'écrasé', 'version' => 1], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['conflict']);

        // Écraser en le sachant passe, et un appel sans version aussi.
        $this->post('backend_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'forcé', 'version' => 1, 'force' => true], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('backend_notes_markdown_update', ['title' => 'Versionnée', 'content' => 'sans version'], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('sans version', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** Somebody else's folder is neither listed nor reachable. */
    public function testSomebodyElsesFolderIsNotListed(): void
    {
        $folder = $this->folder($this->owner, 'Privé');

        $this->client->loginUser($this->other, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_folders_list'));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $body['folders']);

        self::assertNotContains((int) $folder->getId(), $ids);

        $this->client->request('GET', $this->urlGenerator->generate(
            'backend_notes_markdown_folder',
            ['id' => $folder->getId()],
        ));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Le carnet a une page à lui, et elle ne renvoie plus ailleurs.
     *
     * L'adresse redirigeait vers la première note, faute d'écran à montrer :
     * ouvrir le module tombait sur un texte au lieu de montrer ce qu'il y a.
     */
    public function testTheLibraryIsAPageOfItsOwn(): void
    {
        $this->folder($this->owner, 'Clients');
        $this->note($this->owner, 'Une note');

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown'));

        self::assertResponseIsSuccessful();
    }

    /** Un dossier est une adresse, avec son fil d'Ariane résolu côté serveur. */
    public function testAFolderHasItsOwnAddress(): void
    {
        $parent = $this->folder($this->owner, 'Clients');
        $child = $this->folder($this->owner, 'Studio Lumen', $parent);

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate(
            'backend_notes_markdown_folder',
            ['id' => $child->getId()],
        ));

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // Le fil d'Ariane part de la racine : sans lui, un rechargement
        // afficherait la racine le temps que le navigateur recalcule.
        self::assertStringContainsString('Studio Lumen', $html);
        self::assertStringContainsString('Clients', $html);

        // Et surtout : la page dit au composant quel dossier elle est. Sans
        // cette propriété, la bibliothèque s'ouvre sur la racine alors que
        // l'adresse nomme un dossier - ce qu'Axel a vu.
        self::assertMatchesRegularExpression(
            '/&quot;folderId&quot;:\s*'.$child->getId().'\b/',
            $html,
            'la page du dossier doit transmettre son identifiant au composant',
        );
    }

    /** Une note rangée porte son dossier dans la liste, pas un parent. */
    public function testTheFlatListCarriesTheFolder(): void
    {
        $folder = $this->folder($this->owner, 'Clients');
        $note = $this->note($this->owner, 'Devis', $folder);

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_list'));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $row = current(array_filter(
            $body['notes'],
            static fn (array $one): bool => (int) $one['id'] === $note->getId(),
        ));

        self::assertIsArray($row);
        self::assertSame($folder->getId(), (int) $row['folderId']);
    }

    /**
     * L'extrait que montre la mosaïque, calculé côté serveur.
     *
     * Il vaut un déchiffrement par note : huit millisecondes de plus sur un
     * carnet de cinq cents notes, mesuré, ce qui est le prix d'une carte qui
     * montre autre chose qu'un titre. **Il part en Markdown**, pas aplati :
     * la vignette le rend en petit, et c'est de voir un titre et une liste
     * qu'on reconnaît une note.
     */
    public function testTheListCarriesAnExcerpt(): void
    {
        $note = $this->note($this->owner, 'Avec du texte', content: "# Titre\n\n- une liste\n- deux");

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_list'));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $row = current(array_filter(
            $body['notes'],
            static fn (array $one): bool => (int) $one['id'] === $note->getId(),
        ));

        self::assertIsArray($row);
        self::assertSame("# Titre\n\n- une liste\n- deux", $row['excerpt']);
    }

    /**
     * Une image ne part pas dans l'extrait, et un bloc de code coupé se
     * referme.
     *
     * Une seule image en `data:` pèserait plus que toute la liste, et une
     * clôture manquante ferait passer la fin de la vignette pour du code.
     */
    public function testTheExcerptDropsImagesAndClosesWhatItCuts(): void
    {
        $note = $this->note(
            $this->owner,
            'Avec une image',
            content: "![vue](data:image/png;base64,AAAA)\n\nLe texte.\n\n```php\necho 1;",
        );

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_list'));

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
     * Épingler une note, et la décrocher.
     *
     * Le panneau du menu ouvre là-dessus : un carnet a trois ou quatre
     * endroits où l'on retourne tous les jours, et les chercher dans
     * l'arborescence à chaque fois est la corvée que les favoris suppriment.
     */
    public function testANoteCanBePinnedAndUnpinned(): void
    {
        $note = $this->note($this->owner, 'À portée de main');

        $this->client->loginUser($this->owner, 'admin');

        $body = $this->post('backend_notes_markdown_favorite', [], ['id' => $note->getId()]);
        self::assertTrue($body['favorite']);
        self::assertNotNull($this->listedRow((int) $note->getId())['favoritedAt']);

        // Le lecteur le sait aussi : son étoile s'allume.
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertTrue($this->readProps((string) $this->client->getResponse()->getContent())['favorited']);

        $body = $this->post('backend_notes_markdown_favorite', [], ['id' => $note->getId()]);
        self::assertFalse($body['favorite']);
        self::assertNull($this->listedRow((int) $note->getId())['favoritedAt']);
    }

    /**
     * Les favoris sont à la personne : on épingle ce qu'on peut lire, pour
     * soi seul, et jamais ce qu'on ne voit pas.
     */
    public function testFavoritesBelongToWhoPinsThem(): void
    {
        $folder = $this->folder($this->owner, 'Clients');

        $this->client->loginUser($this->other, 'admin');
        $this->post('backend_notes_markdown_folders_favorite', [], ['id' => $folder->getId()]);
        self::assertResponseStatusCodeSame(404);

        // Dans un espace ouvert à tous, chacun épingle pour lui.
        $space = new NoteSpace();
        $space->setOwner($this->owner)->setName('Équipe')->setAccess(NoteSpaceAccessEnum::Backoffice);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->created[] = [NoteSpace::class, (int) $space->getId()];
        $shared = $this->note($this->owner, 'Procédure');
        $shared->setSpace($space);
        $this->entityManager->flush();

        $this->client->loginUser($this->other, 'admin');
        $body = $this->post('backend_notes_markdown_favorite', [], ['id' => $shared->getId()]);
        self::assertTrue($body['favorite']);
        self::assertNotNull($this->listedRow((int) $shared->getId())['favoritedAt']);

        $this->client->loginUser($this->owner, 'admin');
        self::assertNull($this->listedRow((int) $shared->getId())['favoritedAt']);
        $body = $this->post('backend_notes_markdown_folders_favorite', [], ['id' => $folder->getId()]);
        self::assertTrue($body['favorite']);
    }

    /** @return array<string, mixed> */
    private function listedRow(int $id): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_list'));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $row = current(array_filter($body['notes'], static fn (array $one): bool => (int) $one['id'] === $id));
        self::assertIsArray($row);

        return $row;
    }

    /**
     * Les dates de la liste partent en chaînes, pas en objets.
     *
     * L'hydratation en tableau de Doctrine rend des `DateTimeImmutable`, que
     * `json_encode` écrit `{date, timezone_type, timezone}`. Le navigateur
     * n'y voit pas une date : `Intl` lève, et l'exception emporte le
     * composant entier - la page devient un cadre vide. Personne ne l'a vu
     * tant qu'aucun écran n'affichait ces dates.
     */
    public function testTheFlatListSendsDatesAsStrings(): void
    {
        $note = $this->note($this->owner, 'Datée');

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_list'));

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

    /** Les propriétés passées à la page de lecture. */
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

        $folder = $this->post('backend_notes_markdown_folders_create', ['name' => 'Clients']);
        $folderId = $folder['folder']['id'];
        $this->created[] = [NoteFolder::class, (int) $folderId];

        $child = $this->post('backend_notes_markdown_create', [
            'title' => 'Studio Lumen',
            'content' => 'Photo, en cours.',
            'folderId' => $folderId,
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

        // L'arborescence est dans les chemins : une note rangée est un
        // fichier dans le répertoire du nom de son dossier.
        $entries = [];
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $entries[] = (string) $archive->getNameIndex($i);
        }
        $archive->close();

        self::assertContains('Clients/', $entries, 'le dossier est déclaré, même vide');
        self::assertContains('Clients/Studio Lumen.md', $entries);

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_import'),
            files: ['files' => [new UploadedFile($path, 'notes.zip', 'application/zip', null, true)]],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Un dossier et une note : le répertoire traversé compte pour le
        // dossier qu'il est, une seule fois.
        self::assertSame(2, $body['created']);

        foreach ($this->notes() as $note) {
            $this->created[] = [MarkdownNote::class, (int) $note->getId()];
        }

        foreach ($this->folders() as $one) {
            $this->created[] = [NoteFolder::class, (int) $one->getId()];
        }

        // Rien n'est écrasé : l'original et l'importé cohabitent.
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
     * Une archive se suffit : les images partent avec le texte.
     *
     * C'est ce qui manquait. Le markdown portait l'adresse du back-office,
     * donc un carnet exporté s'ouvrait dans Obsidian avec ses images en
     * icônes cassées - ou pire, en demandes de connexion. Le test fait
     * l'aller **et** le retour, parce que la moitié qui compte est celle où
     * l'archive revient : une image réimportée doit redevenir une image de la
     * personne qui importe, pas un lien vers le fichier de quelqu'un d'autre.
     */
    public function testAnExportCarriesItsImagesAndAnImportTakesThemBack(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        // Un PNG d'un pixel, écrit ici plutôt que lu d'un fichier : le test
        // ne dépend d'aucune ressource à côté de lui.
        $pixel = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        $source = (string) tempnam(sys_get_temp_dir(), 'aurora-test-image-');
        file_put_contents($source, $pixel);

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_images_upload'),
            files: ['image' => new UploadedFile($source, 'pixel.png', 'image/png', null, true)],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $upload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $url = $upload['url'];

        // La note est rangée dans un dossier, pour que le chemin relatif ait
        // un cran à remonter : c'est là que l'export se trompait le plus
        // facilement.
        $folder = $this->post('backend_notes_markdown_folders_create', ['name' => 'Illustré']);
        $this->created[] = [NoteFolder::class, (int) $folder['folder']['id']];

        $note = $this->post('backend_notes_markdown_create', [
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
            '/backend/notes/markdown/images/',
            $exported,
            "plus aucune adresse du back-office dans l'archive",
        );

        // Le retour.
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_import'),
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
            '/backend/notes/markdown/images/',
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
