<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMember;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceScope;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceProvider;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_column;
use function array_map;
use function bin2hex;
use function html_entity_decode;
use function json_decode;
use function ksort;
use function preg_match;
use function random_bytes;
use function sprintf;

/**
 * A client space's notes live in a notes space, open to its team.
 *
 * What is held here is the link between the two: the notes space is created
 * on demand, carries the client space's name and its team (the lead manages,
 * a member writes), follows them when they change, and goes to the notes
 * trash when the client space disappears - without taking the notes with
 * it, which an administrator brings back.
 */
final class SpaceNoteSpaceTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $lead;

    private User $member;

    private User $newcomer;

    private User $outsider;

    /** @var list<int> */
    private array $noteSpaces = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->lead = $this->user('referent');
        $this->member = $this->user('membre');
        $this->newcomer = $this->user('arrivant');
        $this->outsider = $this->user('dehors');

        $this->client->loginUser($this->admin(), 'admin');
    }

    private function admin(): User
    {
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);

        return $admin;
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach ([CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        foreach ($this->noteSpaces as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s s WHERE s.id = :id', NoteSpace::class))->setParameter('id', $id)->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s u WHERE u.email LIKE 'notes-espace-%%'", User::class))->execute();

        parent::tearDown();
    }

    public function testTheNoteSpaceOpensOnDemandWithTheTeam(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');

        self::assertNull($space->getNoteSpace(), 'rien avant que quelqu\'un en ait besoin');

        $noteSpace = $this->provider()->resolve($space);
        $this->noteSpaces[] = (int) $noteSpace->getId();

        self::assertSame('Boulangerie Martin', $noteSpace->getName());
        self::assertSame(SpaceNoteSpaceProvider::MANAGED_BY, $noteSpace->getManagedBy());
        self::assertSame(NoteSpaceAccessEnum::Members, $noteSpace->getAccess());
        self::assertNull($noteSpace->getOwner());
        self::assertSame([
            (int) $this->lead->getId() => 'manager',
            (int) $this->member->getId() => 'editor',
        ], $this->rolesIn((int) $noteSpace->getId()));

        // Only once: the second request finds the same one.
        self::assertSame($noteSpace->getId(), $this->provider()->resolve($this->reloaded($space))->getId());
    }

    public function testTheTeamAndTheNameFollowTheSpace(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');
        $noteSpaceId = (int) $this->provider()->resolve($space)->getId();
        $this->noteSpaces[] = $noteSpaceId;

        $this->post(sprintf('/suite/studio/spaces/%d/update', $space->getId()), $this->spacePayload('Boulangerie Martin - Instagram', [
            ['userId' => $this->lead->getId(), 'role' => 'lead'],
            ['userId' => $this->newcomer->getId(), 'role' => 'member'],
        ]));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('Boulangerie Martin - Instagram', $this->entityManager->find(NoteSpace::class, $noteSpaceId)?->getName());
        self::assertSame([
            (int) $this->lead->getId() => 'manager',
            (int) $this->newcomer->getId() => 'editor',
        ], $this->rolesIn($noteSpaceId));
    }

    /** The team writes, and the notes space does not exist for whoever is not on it. */
    public function testTheTeamWritesAndNobodyElseSeesIt(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');
        $noteSpaceId = (int) $this->provider()->resolve($space)->getId();
        $this->noteSpaces[] = $noteSpaceId;

        $this->client->loginUser($this->reference($this->member), 'admin');
        $this->post($this->url('suite_notes_markdown_create'), ['title' => 'Brief', 'spaceId' => $noteSpaceId]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->reference($this->outsider), 'admin');
        $this->post($this->url('suite_notes_markdown_create'), ['title' => 'Intrus', 'spaceId' => $noteSpaceId]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The client space goes for good, its notes do not: their space stays in
     * the notes trash, follows nothing any more, and an administrator brings
     * it back.
     */
    public function testDeletingTheSpaceSendsItsNoteSpaceToTheTrash(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');
        $noteSpaceId = (int) $this->provider()->resolve($space)->getId();
        $this->noteSpaces[] = $noteSpaceId;

        $this->post(sprintf('/suite/studio/spaces/%d/delete', $space->getId()), []);
        self::assertResponseIsSuccessful();
        $this->post(sprintf('/suite/studio/spaces/%d/force-delete', $space->getId()), []);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $noteSpace = $this->entityManager->find(NoteSpace::class, $noteSpaceId);
        self::assertNotNull($noteSpace);
        self::assertNotNull($noteSpace->getDeletedAt());
        self::assertFalse($noteSpace->isManaged());

        $this->post($this->url('suite_notes_spaces_restore', ['id' => $noteSpaceId]), []);
        self::assertResponseIsSuccessful();
    }

    /**
     * The section opens the notes space on the first gesture, then draws the
     * notes editor on it: its lists hold this space alone, and every route it
     * calls carries the host parameter that lets the next request back in.
     */
    public function testTheSectionOpensTheNoteSpaceAndDrawsTheEditorOnIt(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');

        $before = $this->tabOf($space);
        self::assertTrue($before['enabled']);
        self::assertNull($before['noteSpace'], 'pas ouvert tant que personne ne l\'a demandé');
        self::assertNull($before['app']);

        $opened = $this->post($this->url('workspace_space_notes_open', ['id' => $space->getId()]), []);
        self::assertResponseIsSuccessful();
        $noteSpaceId = (int) $opened['noteSpace']['id'];
        $this->noteSpaces[] = $noteSpaceId;

        $this->client->loginUser($this->reference($this->lead), 'admin');
        $this->post($this->url('suite_notes_markdown_create', $this->hostOf($space)), ['title' => 'Brief téléphonique']);
        self::assertResponseIsSuccessful();

        $section = $this->tabOf($space);
        self::assertSame($noteSpaceId, $section['noteSpace']['id']);
        self::assertTrue($section['noteSpace']['canWrite']);
        self::assertSame(['Brief téléphonique'], array_column($section['app']['notes'], 'title'));
        self::assertSame([$noteSpaceId], array_map('intval', array_column($section['app']['spaces'], 'id')));
        self::assertNull($section['app']['personalSpaceId']);
        self::assertStringContainsString('notesHost=', $section['app']['showPath']);
        self::assertSame(sprintf('/workspace/%d?view=notes&note=__id__', $space->getId()), $section['app']['pagePaths']['note']);
    }

    /**
     * The team writes the client's notes without the Notes module: the client
     * space is their way in. The module itself stays closed to them, and so
     * does any other space - the request a host opened reaches nothing else.
     */
    public function testATeamMemberWithoutTheModuleWorksInTheSpaceNotesOnly(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');

        $member = $this->reference($this->member);
        $member->setPrivileges(['studio.spaces.view']);
        $this->entityManager->flush();
        $this->client->loginUser($member, 'admin');

        $opened = $this->post($this->url('workspace_space_notes_open', ['id' => $space->getId()]), []);
        self::assertResponseIsSuccessful();
        $this->noteSpaces[] = (int) $opened['noteSpace']['id'];
        self::assertNotNull($this->tabOf($space)['app']);

        $created = $this->post($this->url('suite_notes_markdown_create', $this->hostOf($space)), ['title' => 'Relance']);
        self::assertResponseIsSuccessful();
        $noteId = (int) $created['note']['id'];

        $this->client->xmlHttpRequest('GET', $this->url('suite_notes_markdown_show', ['id' => $noteId] + $this->hostOf($space)));
        self::assertResponseIsSuccessful();

        // The module, without the host parameter: closed.
        $this->client->xmlHttpRequest('GET', $this->url('suite_notes_markdown_list'));
        self::assertResponseStatusCodeSame(403);

        // An image's address carries no host parameter: the route answers
        // over the hosted spaces only, so an unknown file is a plain 404,
        // not a refusal - and no personal notebook is opened on the way.
        $this->client->request('GET', $this->url('suite_notes_markdown_images_serve', ['filename' => 'absente.webp']));
        self::assertResponseStatusCodeSame(404);
        self::assertNull(static::getContainer()->get(NoteSpaceRepository::class)->findPersonalFor($this->reference($this->member)));

        // A client space they are not in: the host does not let them in.
        $this->client->loginUser($this->admin(), 'admin');
        $other = $this->givenSpace('Fromagerie Roux', [['userId' => $this->outsider->getId(), 'role' => 'lead']]);
        $this->noteSpaces[] = (int) $this->provider()->resolve($this->reloaded($other))->getId();
        $this->client->loginUser($this->reference($this->member), 'admin');
        $this->client->xmlHttpRequest('GET', $this->url('suite_notes_markdown_list', $this->hostOf($other)));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Opened for one space, a request reaches no note of another, even one
     * the person could read in the Notes module.
     */
    public function testAHostedRequestReachesNoOtherSpace(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');
        $this->noteSpaces[] = (int) $this->provider()->resolve($space)->getId();

        $this->client->loginUser($this->reference($this->lead), 'admin');
        $personal = $this->post($this->url('suite_notes_markdown_create'), ['title' => 'Mes idées']);
        self::assertResponseIsSuccessful();

        $this->client->xmlHttpRequest('GET', $this->url('suite_notes_markdown_show', ['id' => $personal['note']['id']] + $this->hostOf($space)));
        self::assertResponseStatusCodeSame(404);

        $this->client->xmlHttpRequest('GET', $this->url('suite_notes_markdown_list', $this->hostOf($space)));
        self::assertResponseIsSuccessful();
        $listed = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotContains('Mes idées', array_column($listed['notes'] ?? [], 'title'));
    }

    /**
     * The Notes module does not list a client space's notes, and a note's old
     * address in the module - a notification, a bookmark - leads to the
     * client space.
     */
    public function testTheModuleLeavesHostedNotesToTheirSpace(): void
    {
        $space = $this->givenSpace('Boulangerie Martin');
        $this->noteSpaces[] = (int) $this->provider()->resolve($space)->getId();

        $this->client->loginUser($this->reference($this->lead), 'admin');
        $created = $this->post($this->url('suite_notes_markdown_create', $this->hostOf($space)), ['title' => 'Brief du client']);
        $noteId = (int) $created['note']['id'];

        $this->client->xmlHttpRequest('GET', $this->url('suite_notes_markdown_list'));
        self::assertResponseIsSuccessful();
        $listed = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotContains('Brief du client', array_column($listed['notes'] ?? [], 'title'));

        $this->client->request('GET', $this->url('suite_notes_markdown_show', ['id' => $noteId]));
        self::assertResponseRedirects(sprintf('/workspace/%d?view=notes&note=%d', $space->getId(), $noteId));
    }

    /** @return array<string, string> */
    private function hostOf(CustomerSpace $space): array
    {
        return [NoteSpaceScope::HOST_PARAMETER => SpaceNoteSpaceProvider::MANAGED_BY.':'.$space->getId()];
    }

    /**
     * The tab's state as the page renders it.
     *
     * @return array<string, mixed>
     */
    private function tabOf(CustomerSpace $space): array
    {
        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));
        self::assertResponseIsSuccessful();

        self::assertSame(1, preg_match('/data-symfony--ux-vue--vue-props-value="([^"]*spaceNotes[^"]*)"/', (string) $this->client->getResponse()->getContent(), $match));
        $props = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);

        return $props['spaceNotes'];
    }

    private function provider(): SpaceNoteSpaceProvider
    {
        return static::getContainer()->get(SpaceNoteSpaceProvider::class);
    }

    /**
     * A client space led by `lead`, with `member` on the team, unless a team
     * is given.
     *
     * @param ?list<array{userId: ?int, role: string}> $team
     */
    private function givenSpace(string $name, ?array $team = null): CustomerSpace
    {
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy(['legalName' => 'Client des notes']);
        if (!$customer instanceof Customer) {
            $customer = new Customer();
            $customer
                ->setLegalName('Client des notes')
                ->setSiret('73282932000074')
                ->setContractualEmail('notes@example.test');
            $this->entityManager->persist($customer);
            $this->entityManager->flush();
        }

        $body = $this->post('/suite/studio/spaces/create', [
            ...$this->spacePayload($name, $team ?? [
                ['userId' => $this->lead->getId(), 'role' => 'lead'],
                ['userId' => $this->member->getId(), 'role' => 'member'],
            ]),
            'customerId' => $customer->getId(),
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->find(CustomerSpace::class, $body['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /**
     * @param list<array{userId: ?int, role: string}> $members
     *
     * @return array<string, mixed>
     */
    private function spacePayload(string $name, array $members): array
    {
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy(['legalName' => 'Client des notes']);

        return [
            'name' => $name,
            'customerId' => $customer?->getId(),
            'timezone' => 'Europe/Paris',
            'status' => 'active',
            'members' => $members,
        ];
    }

    /** @return array<int, string> */
    private function rolesIn(int $noteSpaceId): array
    {
        $roles = [];
        foreach ($this->entityManager->getRepository(NoteSpaceMember::class)->findBy(['space' => $noteSpaceId]) as $membership) {
            $roles[(int) $membership->getUser()->getId()] = $membership->getRole()->value;
        }

        ksort($roles);

        return $roles;
    }

    private function reloaded(CustomerSpace $space): CustomerSpace
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return $fresh;
    }

    private function reference(User $user): User
    {
        $managed = $this->entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setEmail(sprintf('notes-espace-%s-%s@aurora.test', $name, bin2hex(random_bytes(4))))
            ->setName($name)
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['notes.markdown.use', 'studio.spaces.view'])
            ->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /** @param array<string, scalar> $parameters */
    private function url(string $route, array $parameters = []): string
    {
        return static::getContainer()->get(UrlGeneratorInterface::class)->generate($route, $parameters);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $payload): array
    {
        $this->client->jsonRequest('POST', $url, $payload);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
