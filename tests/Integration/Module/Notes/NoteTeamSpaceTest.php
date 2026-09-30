<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Share\Service\SharedNoteScope;
use Aurora\Module\Notes\Space\NoteSpaceEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_column;
use function array_reverse;
use function base64_decode;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;

/**
 * L'espace de l'équipe : tout le monde le lit, ceux qui en ont le droit y
 * écrivent.
 *
 * Des comptes sans rôle d'administrateur, exprès : un administrateur a tous
 * les droits d'Aurora, et un test fait avec lui ne prouverait rien sur le
 * droit `notes.team.edit`.
 */
final class NoteTeamSpaceTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    /** Lit le module, n'écrit pas dans l'équipe. */
    private User $reader;

    /** Écrit dans l'équipe. */
    private User $writer;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $this->reader = $this->user('equipe-lecteur@aurora.test', ['notes.markdown.use']);
        $this->writer = $this->user('equipe-redacteur@aurora.test', ['notes.markdown.use', NoteSpaceEnum::TEAM_EDIT]);
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

        parent::tearDown();
    }

    public function testEverybodyReadsTheTeamAndOnlyTheRightWritesIt(): void
    {
        $this->client->loginUser($this->writer, 'admin');
        $note = $this->post('backend_notes_markdown_create', ['title' => 'Procédure', 'content' => 'Étapes', 'space' => 'team']);
        self::assertResponseIsSuccessful();
        $id = (int) $note['note']['id'];
        $this->created[] = [MarkdownNote::class, $id];
        self::assertSame('team', $note['note']['space']);

        // Le lecteur la voit dans sa liste et peut la lire...
        $this->client->loginUser($this->reader, 'admin');
        self::assertContains($id, $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $id]));
        self::assertResponseIsSuccessful();

        // ... mais ni l'écrire, ni en créer, ni ouvrir l'éditeur.
        $this->post('backend_notes_markdown_update', ['title' => 'Détournée', 'content' => 'x'], ['id' => $id]);
        self::assertResponseStatusCodeSame(404);
        $this->post('backend_notes_markdown_create', ['title' => 'Intruse', 'space' => 'team']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_show', ['id' => $id]));
        self::assertResponseRedirects($this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $id]));
    }

    /**
     * Une note d'équipe s'écrit par le droit, pas parce qu'on l'a créée : un
     * rédacteur écrit dans la note d'un autre, et l'auteur qui perd le droit
     * ne l'écrit plus.
     */
    public function testWritingFollowsTheRightNotTheAuthor(): void
    {
        $note = $this->note($this->reader, 'Écrite par le lecteur', NoteSpaceEnum::Team);

        $this->client->loginUser($this->writer, 'admin');
        $this->post('backend_notes_markdown_update', ['title' => 'Relue', 'content' => 'par le rédacteur'], ['id' => $note->getId()]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->reader, 'admin');
        $this->post('backend_notes_markdown_update', ['title' => 'Mienne', 'content' => 'pourtant'], ['id' => $note->getId()]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPersonalNotebookStaysPersonal(): void
    {
        $private = $this->note($this->writer, 'Journal du rédacteur', NoteSpaceEnum::Personal);

        $this->client->loginUser($this->reader, 'admin');
        self::assertNotContains($private->getId(), $this->listedIds());
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_read', ['id' => $private->getId()]));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Ranger un dossier dans l'équipe emporte toute la branche, et le
     * rapatrier dans un carnet la rend à qui la range.
     */
    public function testAFolderChangesSpaceWithEverythingInIt(): void
    {
        $folder = $this->folder($this->writer, 'Onboarding', NoteSpaceEnum::Personal);
        $sub = $this->folder($this->writer, 'Semaine 1', NoteSpaceEnum::Personal, $folder);
        $note = $this->note($this->writer, 'Accueil', NoteSpaceEnum::Personal, $sub);

        $this->client->loginUser($this->writer, 'admin');
        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'space' => 'team'], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(NoteFolder::class, $sub->getId())?->isTeam());
        self::assertTrue($this->entityManager->find(MarkdownNote::class, $note->getId())?->isTeam());

        // Le lecteur ne peut pas le reprendre dans son carnet...
        $this->client->loginUser($this->reader, 'admin');
        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'space' => 'personal'], ['id' => $folder->getId()]);
        self::assertResponseStatusCodeSame(404);

        // ... le rédacteur, si : la branche redevient la sienne.
        $this->client->loginUser($this->writer, 'admin');
        $this->post('backend_notes_markdown_folders_move', ['parentId' => null, 'space' => 'personal'], ['id' => $folder->getId()]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $back = $this->entityManager->find(MarkdownNote::class, $note->getId());
        self::assertFalse($back?->isTeam());
        self::assertSame($this->writer->getId(), $back?->getUser()->getId());
    }

    /** Un lien public ne suit jamais un wiki-lien vers le carnet de l'équipe. */
    public function testAPublicLinkNeverWalksIntoTheTeam(): void
    {
        $root = $this->note($this->writer, 'Présentation', NoteSpaceEnum::Personal, content: 'Voir [[Tarifs internes]]');
        $this->note($this->writer, 'Tarifs internes', NoteSpaceEnum::Team, content: 'Confidentiel');

        $walked = static::getContainer()->get(SharedNoteScope::class)->walk($root, true);

        self::assertSame(['Présentation'], array_column(array_map(
            static fn (MarkdownNote $one): array => ['t' => $one->getTitle()],
            $walked,
        ), 't'));
    }

    /**
     * Une image posée dans une note d'équipe s'affiche chez tous ses
     * lecteurs ; seul un rédacteur peut en poser une.
     */
    public function testTeamImagesAreSeenByEveryReader(): void
    {
        $source = (string) tempnam(sys_get_temp_dir(), 'aurora-test-image-');
        file_put_contents($source, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('backend_notes_markdown_images_upload'), ['space' => 'team'], ['image' => new UploadedFile($source, 'pixel.png', 'image/png', null, true)]);
        self::assertResponseStatusCodeSame(404);

        file_put_contents($source, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $this->client->loginUser($this->writer, 'admin');
        $this->client->request('POST', $this->urlGenerator->generate('backend_notes_markdown_images_upload'), ['space' => 'team'], ['image' => new UploadedFile($source, 'pixel.png', 'image/png', null, true)]);
        self::assertResponseIsSuccessful();
        $filename = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['filename'];

        $this->client->loginUser($this->reader, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown_images_serve', ['filename' => $filename]));
        self::assertResponseIsSuccessful();
    }

    /** @param list<string> $privileges */
    private function user(string $email, array $privileges): User
    {
        $user = new User();
        $user->setEmail($email)
            ->setName($email)
            ->setType(UserTypeEnum::Backend)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges($privileges)
            ->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->created[] = [User::class, (int) $user->getId()];

        return $user;
    }

    private function note(User $user, string $title, NoteSpaceEnum $space, ?NoteFolder $folder = null, string $content = ''): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($user)->setTitle($title)->setContent($content)->setSpace($space);
        if (null !== $folder) {
            $note->setFolder($folder);
        }
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        return $note;
    }

    private function folder(User $user, string $name, NoteSpaceEnum $space, ?NoteFolder $parent = null): NoteFolder
    {
        $folder = new NoteFolder();
        $folder->setUser($user)->setName($name)->setParent($parent)->setSpace($space);
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

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return array_map(static fn (array $one): int => (int) $one['id'], $body['notes'] ?? []);
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
