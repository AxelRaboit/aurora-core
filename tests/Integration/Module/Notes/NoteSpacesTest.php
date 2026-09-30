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
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use ZipArchive;

use function array_column;
use function array_map;
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
 * Les espaces : chacun dit qui le lit et qui l'écrit, et rien d'autre ne
 * décide.
 *
 * Des comptes sans rôle d'administrateur, exprès : un administrateur a tous
 * les droits d'Aurora, et un test fait avec lui ne prouverait rien sur les
 * rôles d'un espace.
 */
final class NoteSpacesTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    /** Crée l'espace partagé : il en est le propriétaire. */
    private User $owner;

    /** Inscrit comme rédacteur. */
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
        // Un seul noyau pour tout le test : les comptes et les espaces créés
        // ici restent ceux que les requêtes voient.
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $this->owner = $this->user('proprietaire');
        $this->editor = $this->user('redacteur');
        $this->reader = $this->user('lecteur');
        $this->outsider = $this->user('dehors');
    }

    /**
     * Supprimer les comptes suffit : leurs espaces personnels partent avec
     * eux par la base, et les espaces partagés dont ils sont propriétaires
     * sont supprimés à part, avec ce qu'ils contiennent.
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
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $private->getId()]));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_show', ['id' => $private->getId()]));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Un espace ouvert au back-office se lit par tous, et s'écrit selon le
     * rôle : lire veut dire lire, pas modifier, et l'éditeur conduit au
     * lecteur.
     */
    public function testABackofficeSpaceIsReadByEverybodyAndWrittenByItsEditors(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);
        $note = $this->note($this->owner, 'Procédure', $space, content: 'Le texte d\'origine');

        $this->client->loginUser($this->outsider, 'admin');
        self::assertContains($note->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();

        $this->post('backend_notes_markdown_update', ['title' => 'Détournée', 'content' => 'x', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_show', ['id' => $note->getId()]));
        self::assertResponseRedirects($this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));

        $this->client->loginUser($this->editor, 'admin');
        $this->post('backend_notes_markdown_update', ['title' => 'Procédure', 'content' => 'Relue', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('Relue', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** Un espace aux membres n'existe pas pour qui n'y est pas inscrit. */
    public function testAMembersSpaceIsInvisibleToEverybodyElse(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $folder = $this->folder($this->owner, 'Clients', $space);
        $note = $this->note($this->owner, 'Devis', $space, $folder);

        $this->client->loginUser($this->outsider, 'admin');
        self::assertNotContains($note->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_folder', ['id' => $folder->getId()]));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->reader, 'admin');
        self::assertContains($note->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();
    }

    /**
     * Écrire suit le rôle, pas l'auteur : un lecteur n'écrit pas la note
     * qu'il a écrite quand il était rédacteur, un rédacteur écrit celle d'un
     * autre.
     */
    public function testWritingFollowsTheRoleNotTheAuthor(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $note = $this->note($this->reader, 'Écrite par le lecteur', $space);

        $this->client->loginUser($this->reader, 'admin');
        $this->post('backend_notes_markdown_update', ['title' => 'Mienne', 'content' => 'pourtant', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('backend_notes_markdown_update', ['title' => 'Relue', 'content' => 'par le rédacteur', 'force' => true], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();
    }

    /** Créer dans un espace demande d'y écrire. */
    public function testCreatingInASpaceNeedsTheEditorRole(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);

        $this->client->loginUser($this->reader, 'admin');
        $this->post('backend_notes_markdown_create', ['title' => 'Intruse', 'spaceId' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('backend_notes_markdown_folders_create', ['name' => 'Intrus', 'spaceId' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->editor, 'admin');
        $body = $this->post('backend_notes_markdown_create', ['title' => 'Bienvenue', 'spaceId' => $space->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame($space->getId(), $body['note']['spaceId']);

        // Sans espace dit, une note naît dans son espace personnel.
        $mine = $this->post('backend_notes_markdown_create', ['title' => 'Pour moi']);
        self::assertResponseIsSuccessful();
        self::assertSame($this->personalSpaceOf($this->editor)->getId(), $mine['note']['spaceId']);
    }

    /**
     * Un dossier change d'espace avec toute sa branche ; seul celui qui peut
     * écrire aux deux bouts le déplace.
     */
    public function testAFolderChangesSpaceWithEverythingInIt(): void
    {
        $team = $this->space(NoteSpaceAccessEnum::Backoffice);
        $personal = $this->personalSpaceOf($this->editor);
        $folder = $this->folder($this->editor, 'Onboarding', $personal);
        $sub = $this->folder($this->editor, 'Semaine 1', $personal, $folder);
        $note = $this->note($this->editor, 'Accueil', $personal, $sub);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $team->getId()], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame($team->getId(), $this->entityManager->find(NoteFolder::class, $sub->getId())?->getSpace()->getId());
        self::assertSame($team->getId(), $this->entityManager->find(MarkdownNote::class, $note->getId())?->getSpace()->getId());

        // Le lecteur ne l'emporte pas dans son carnet...
        $this->client->loginUser($this->reader, 'admin');
        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $this->personalSpaceOf($this->reader)->getId()], ['id' => $folder->getId()]);
        self::assertResponseStatusCodeSame(404);

        // ... et personne ne range rien dans le carnet d'un autre.
        $this->client->loginUser($this->editor, 'admin');
        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $this->personalSpaceOf($this->reader)->getId()], ['id' => $folder->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $personal->getId()], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame($personal->getId(), $this->entityManager->find(MarkdownNote::class, $note->getId())?->getSpace()->getId());
    }

    /**
     * Un wiki-lien se résout dans l'espace de la note lue : ni chez le
     * lecteur, ni dans le carnet privé de l'auteur. Et la lecture ne donne
     * ni l'écriture ni le carnet de l'auteur.
     */
    public function testLinksResolveInsideTheSpaceOfTheNote(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);
        $guide = $this->note($this->owner, 'Guide', $space, content: 'Voir [[Budget]] et [[Secret]]');
        $budget = $this->note($this->owner, 'Budget', $space, content: 'Chiffres');
        $this->note($this->owner, 'Secret', $this->personalSpaceOf($this->owner), content: 'Privé');
        $mine = $this->note($this->reader, 'Budget', $this->personalSpaceOf($this->reader), content: 'Le mien');

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $guide->getId()]));
        self::assertResponseIsSuccessful();

        $props = $this->readProps((string) $this->client->getResponse()->getContent());

        self::assertSame($budget->getId(), $props['titleIndex']['budget'] ?? null);
        self::assertNotSame($mine->getId(), $props['titleIndex']['budget'] ?? null);
        self::assertArrayNotHasKey('secret', $props['titleIndex']);
        self::assertFalse($props['canEdit']);
    }

    /**
     * Les images d'un espace s'affichent chez tous ses lecteurs, et chez
     * eux seuls ; seul qui écrit dans l'espace en pose.
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
        $url = $this->urlGenerator->generate('backend_notes_markdown_images_read', ['noteId' => $note->getId(), 'filename' => $filename]);

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // Une image que la note ne cite pas reste fermée, même par elle.
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_images_read', ['noteId' => $note->getId(), 'filename' => 'autre-'.$filename]));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Le lien de partage d'une note d'espace sert ses images : elles vivent
     * dans le compartiment de l'espace, pas chez l'auteur.
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
     * Un espace s'emporte seul, à la racine de l'archive, et une archive se
     * verse à la racine d'un espace où l'on écrit - jamais d'un autre.
     */
    public function testASpaceIsExportedAndImportedOnItsOwn(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $folder = $this->folder($this->owner, 'Guides', $space);
        $this->note($this->owner, 'Accueil', $space, $folder);
        $this->note($this->owner, 'Journal', $this->personalSpaceOf($this->owner));

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_export', ['spaceId' => $space->getId()]));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_export', ['spaceId' => $space->getId()]));
        self::assertResponseIsSuccessful();

        $path = static::getContainer()->get(MarkdownNoteArchive::class)->zipFor($this->managed($this->owner), $this->managed($space));
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));
        $entries = [];
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $entries[] = (string) $archive->getNameIndex($i);
        }
        $archive->close();

        self::assertContains('Guides/Accueil.md', $entries, 'à la racine, sans le dossier de l\'espace');
        self::assertNotContains('Journal.md', $entries, 'rien de son carnet personnel');

        $target = $this->space(NoteSpaceAccessEnum::Members);

        // Un lecteur ne verse rien dans l'espace.
        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('backend_notes_markdown_import'), ['spaceId' => (string) $target->getId()], ['files' => [new UploadedFile($path, 'espace.zip', 'application/zip', null, true)]]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->editor, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('backend_notes_markdown_import'), ['spaceId' => (string) $target->getId()], ['files' => [new UploadedFile($path, 'espace.zip', 'application/zip', null, true)]]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $imported = $this->entityManager->getRepository(MarkdownNote::class)->findBy(['space' => $target->getId()]);
        self::assertSame(['Accueil'], array_map(static fn (MarkdownNote $one): string => (string) $one->getTitle(), $imported));
        self::assertSame('Guides', $imported[0]->getFolder()?->getName());
        self::assertSame($target->getId(), $imported[0]->getFolder()?->getSpace()->getId());
    }

    /**
     * Ce qui dort à la corbeille suit sa branche : restauré, il retrouve son
     * dossier dans le même espace.
     */
    public function testATrashedNoteFollowsItsFolderAcrossSpaces(): void
    {
        $team = $this->space(NoteSpaceAccessEnum::Backoffice);
        $personal = $this->personalSpaceOf($this->editor);
        $folder = $this->folder($this->editor, 'Projet', $personal);
        $trashed = $this->note($this->editor, 'Brouillon jeté', $personal, $folder);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('backend_notes_markdown_delete', [], ['id' => $trashed->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'spaceId' => $team->getId()], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame($team->getId(), $this->entityManager->find(MarkdownNote::class, $trashed->getId())?->getSpace()->getId());
    }

    /**
     * Un espace dont le propriétaire est parti revient aux administrateurs :
     * sinon plus personne ne pourrait le régler ni le faire revenir.
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
        $this->post('backend_notes_spaces_update', ['name' => 'Repris', 'access' => 'members'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404, 'un rédacteur ne gère pas');

        $this->client->loginUser($this->managed($admin), 'admin');
        $this->post('backend_notes_spaces_update', ['name' => 'Repris', 'access' => 'members'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('backend_notes_spaces_delete', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('backend_notes_spaces_restore', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
    }

    /** Un gestionnaire ne ferme pas l'espace à « moi seul » : il s'en fermerait la porte. */
    public function testOnlyTheOwnerKeepsASpaceToThemselves(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $manager = $this->managed($this->editor);
        $member = $this->entityManager->getRepository(NoteSpaceMember::class)->findOneBy(['space' => $space->getId(), 'user' => $manager->getId()]);
        $member?->setRole(NoteSpaceRoleEnum::Manager);
        $this->entityManager->flush();

        $this->client->loginUser($manager, 'admin');
        $body = $this->post('backend_notes_spaces_update', ['name' => 'Fermé', 'access' => 'private'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('notes.markdown.spaces.errors.private_owner_only', $body['errors']['access'] ?? null);

        $this->client->loginUser($this->managed($this->owner), 'admin');
        $this->post('backend_notes_spaces_update', ['name' => 'Fermé', 'access' => 'private'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
    }

    /** La liste des personnes ne sert qu'à qui peut inscrire quelqu'un. */
    public function testThePeopleListIsForThoseWhoCanAddSomeone(): void
    {
        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_spaces_people'));
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['people']);

        $this->space(NoteSpaceAccessEnum::Members);
        $this->client->loginUser($this->managed($this->owner), 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_spaces_people'));
        self::assertNotSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['people']);
    }

    /** Un lien public ne suit jamais un wiki-lien hors de son espace. */
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
     * Ce qu'une personne a écrit dans un espace partagé lui survit ; son
     * carnet personnel part avec elle.
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

    /** Créer un espace partagé est un droit ; qui le crée le gère. */
    public function testCreatingASpaceNeedsTheRight(): void
    {
        $this->client->loginUser($this->reader, 'admin');
        $this->post('backend_notes_spaces_create', ['name' => 'Refusé', 'access' => 'backoffice']);
        self::assertResponseStatusCodeSame(403);

        $creator = $this->user('createur', ['notes.markdown.use', 'notes.spaces.create']);
        $this->client->loginUser($creator, 'admin');
        $this->post('backend_notes_spaces_create', ['name' => '', 'access' => 'backoffice']);
        self::assertResponseStatusCodeSame(422);

        $body = $this->post('backend_notes_spaces_create', ['name' => 'Documentation', 'access' => 'members', 'defaultRole' => 'reader']);
        self::assertResponseIsSuccessful();
        self::assertSame('manager', $body['space']['role']);
        self::assertTrue($body['space']['isOwner']);
        self::assertSame('members', $body['space']['access']);

        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_spaces_list'));
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($list['canCreate']);
        self::assertTrue($list['spaces'][0]['personal'], 'le sien en tête');
        self::assertContains($body['space']['id'], array_column($list['spaces'], 'id'));
    }

    /** La liste dit le rôle de chacun dans chaque espace. */
    public function testTheListCarriesEachRole(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_spaces_list'));
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $byId = array_column($list['spaces'], null, 'id');

        self::assertSame('reader', $byId[$space->getId()]['role']);
        self::assertFalse($byId[$space->getId()]['canWrite']);
        self::assertFalse($list['canCreate']);

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_spaces_list'));
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotContains($space->getId(), array_column($list['spaces'], 'id'));
    }

    /**
     * Seul qui gère règle l'espace et ses inscrits ; une inscription ouvre
     * l'espace, la retirer le referme.
     */
    public function testOnlyManagersChangeSettingsAndMembers(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $note = $this->note($this->owner, 'Devis', $space);

        $this->client->loginUser($this->editor, 'admin');
        $this->post('backend_notes_spaces_update', ['name' => 'Détourné', 'access' => 'backoffice'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('backend_notes_spaces_members_set', ['userId' => $this->outsider->getId(), 'role' => 'editor'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_spaces_members_set', ['userId' => $this->outsider->getId(), 'role' => 'reader'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->post('backend_notes_spaces_members_set', ['userId' => $this->owner->getId(), 'role' => 'reader'], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(422, 'le propriétaire ne se rétrograde pas');

        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_spaces_show', ['id' => $space->getId()]));
        $shown = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains($this->outsider->getId(), array_column($shown['members'], 'userId'));

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_spaces_members_remove', [], ['id' => $space->getId(), 'userId' => $this->outsider->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->outsider, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);
    }

    /** Son espace personnel ne s'ouvre à personne et ne se retire pas. */
    public function testThePersonalSpaceStaysClosed(): void
    {
        $personal = $this->personalSpaceOf($this->owner);

        $this->client->loginUser($this->owner, 'admin');
        $body = $this->post('backend_notes_spaces_update', ['name' => 'Ouvert', 'access' => 'backoffice', 'color' => '#aa3300'], ['id' => $personal->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('private', $body['space']['access']);
        self::assertNull($body['space']['name']);
        self::assertSame('#aa3300', $body['space']['color']);

        $this->post('backend_notes_spaces_delete', [], ['id' => $personal->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('backend_notes_spaces_members_set', ['userId' => $this->reader->getId(), 'role' => 'reader'], ['id' => $personal->getId()]);
        self::assertResponseStatusCodeSame(404);
    }

    /** Un espace retiré disparaît pour tout le monde, et son propriétaire le fait revenir. */
    public function testARemovedSpaceComesBackWithItsNotes(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Backoffice);
        $note = $this->note($this->owner, 'Procédure', $space);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_spaces_delete', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseStatusCodeSame(404);
        $this->post('backend_notes_spaces_restore', [], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_spaces_restore', [], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $note->getId()]));
        self::assertResponseIsSuccessful();
    }

    /** Publier est un droit à part, en plus de gérer l'espace ; jamais son espace personnel. */
    public function testPublishingNeedsItsOwnRight(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);

        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_spaces_publish', ['published' => true], ['id' => $space->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->grantPublishing();
        $body = $this->post('backend_notes_spaces_publish', ['published' => true, 'slug' => 'Guide Équipe'], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('guide-equipe', $body['space']['slug']);
        self::assertTrue($body['space']['published']);
        self::assertStringEndsWith('/p/guide-equipe', (string) $body['space']['publicUrl']);

        $this->post('backend_notes_spaces_publish', ['published' => true], ['id' => $this->personalSpaceOf($this->owner)->getId()]);
        self::assertResponseStatusCodeSame(404);

        $other = $this->space(NoteSpaceAccessEnum::Members);
        $this->post('backend_notes_spaces_publish', ['published' => true, 'slug' => 'guide-equipe'], ['id' => $other->getId()]);
        self::assertResponseStatusCodeSame(422, 'une adresse déjà prise');
    }

    /**
     * Un espace publié se lit sans compte : son entrée mène à sa première
     * note, les liens restent dans l'espace, et les moteurs sont tenus à
     * l'écart par défaut.
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

    /** Rien d'autre ne s'ouvre par l'adresse d'un espace publié. */
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

        // Dépublié, il disparaît comme s'il n'avait jamais existé.
        $this->client->loginUser($this->owner, 'admin');
        $this->post('backend_notes_spaces_publish', ['published' => false], ['id' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $this->client->restart();
        $this->client->request('GET', '/p/'.$slug);
        self::assertResponseStatusCodeSame(404);
    }

    /** Un espace qui le demande laisse les moteurs l'indexer. */
    public function testAnIndexableSpaceLetsEnginesIn(): void
    {
        $space = $this->space(NoteSpaceAccessEnum::Members);
        $note = $this->note($this->owner, 'Documentation', $space);
        $slug = $this->publish($space, indexable: true);

        $this->client->restart();
        $this->client->request('GET', '/p/'.$slug.'/'.$note->getId());

        self::assertResponseIsSuccessful();
        // Le mode debug de Symfony pose `noindex` sur toute réponse, en dev
        // comme en test ; ce qui compte ici est que la page ne pose pas le sien.
        self::assertNotSame('noindex, nofollow, noarchive', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="index, follow">', (string) $this->client->getResponse()->getContent());
    }

    /** Le propriétaire reçoit le droit de publier, et l'espace est publié ; rend son adresse. */
    private function publish(NoteSpaceInterface $space, bool $indexable = false): string
    {
        $this->grantPublishing();
        $body = $this->post('backend_notes_spaces_publish', ['published' => true, 'slug' => 'espace-'.$space->getId(), 'indexable' => $indexable], ['id' => $space->getId()]);
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
     * Un espace partagé, dont le propriétaire est `owner`, où `editor` est
     * rédacteur et `reader` lecteur.
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
     * L'entité suivie par le gestionnaire d'entités.
     *
     * Le noyau le remet à zéro après chaque requête : ce que le test tient
     * depuis avant n'est plus suivi, et Doctrine le prendrait pour du neuf.
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
            ->setType(UserTypeEnum::Backend)
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
            $this->urlGenerator->generate('backend_notes_markdown_images_upload'),
            ['spaceId' => (string) $space->getId()],
            ['image' => new UploadedFile($source, 'pixel.png', 'image/png', null, true)],
        );
    }

    /** @return list<int> */
    private function listedIds(): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_list'));
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
     * @param array<string, scalar> $params
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
