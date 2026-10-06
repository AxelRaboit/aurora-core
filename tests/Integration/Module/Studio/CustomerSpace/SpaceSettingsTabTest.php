<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceProvider;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function bin2hex;
use function html_entity_decode;
use function json_decode;
use function preg_match;
use function random_bytes;
use function sprintf;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/**
 * A space's Settings tab gathers everything about the space: its form for
 * whoever may edit it, its team and its Drive for the lead.
 *
 * What would break quietly: the form offered to a reader who cannot save it,
 * a member changing the team from the tab, a save that skipped the note-space
 * sync, or the Drive opened to an editor who is not the lead.
 */
final class SpaceSettingsTabTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    private User $lead;

    private User $editor;

    private User $reader;

    /** @var list<int> */
    private array $noteSpaces = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;

        $this->lead = $this->user('lead', ['studio.spaces.view', 'studio.spaces.edit', 'notes.markdown.use']);
        $this->editor = $this->user('editor', ['studio.spaces.view', 'studio.spaces.edit', 'notes.markdown.use']);
        $this->reader = $this->user('reader', ['studio.spaces.view']);

        $this->client->loginUser($admin, 'admin');
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

        $this->entityManager->createQuery(sprintf("DELETE FROM %s u WHERE u.email LIKE 'reglages-espace-%%'", User::class))->execute();

        parent::tearDown();
    }

    /**
     * The form is handed to whoever may edit the space, the team and the
     * Drive only to its lead; a reader gets neither.
     */
    public function testTheTabCarriesTheFormForEditorsAndTheTeamForTheLead(): void
    {
        $space = $this->givenSpace();

        $this->client->loginUser($this->lead, 'admin');
        [$settings, $canConfigure] = $this->tabOf($space);
        self::assertTrue($canConfigure);
        self::assertIsArray($settings);
        self::assertTrue($settings['canConfigure']);
        self::assertSame(sprintf('/suite/studio/spaces/%d/update', $space->getId()), $settings['updatePath']);
        self::assertSame('Boulangerie Martin', $settings['space']['name']);
        self::assertContains('Europe/Paris', $settings['timezones']);
        self::assertContains((int) $this->editor->getId(), array_column($settings['users'], 'id'));

        $this->client->loginUser($this->editor, 'admin');
        [$settings, $canConfigure] = $this->tabOf($space);
        self::assertFalse($canConfigure);
        self::assertIsArray($settings);
        self::assertFalse($settings['canConfigure']);
        // The Drive stays the lead's.
        $this->client->request('GET', sprintf('/workspace/%d/settings', $space->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->reader, 'admin');
        [$settings] = $this->tabOf($space);
        self::assertNull($settings);
    }

    /**
     * An editor saves the space's fields from the tab; the team stays the
     * lead's, and a reader saves nothing.
     */
    public function testAnEditorSavesTheSpaceButNotItsTeam(): void
    {
        $space = $this->givenSpace();
        $team = [
            ['userId' => $this->lead->getId(), 'role' => 'lead'],
            ['userId' => $this->editor->getId(), 'role' => 'member'],
            ['userId' => $this->reader->getId(), 'role' => 'member'],
        ];

        $this->client->loginUser($this->editor, 'admin');
        [$settings] = $this->tabOf($space);
        $this->client->jsonRequest('POST', (string) $settings['updatePath'], $this->payload([
            'name' => 'Boulangerie Martin - Instagram',
            'description' => 'Deux publications par semaine.',
            'colourSlot' => 5,
            'timezone' => 'America/Toronto',
            'status' => 'archived',
            'members' => $team,
        ]));
        self::assertResponseIsSuccessful();

        $saved = $this->reloaded($space);
        self::assertSame('Boulangerie Martin - Instagram', $saved->getName());
        self::assertSame('Deux publications par semaine.', $saved->getDescription());
        self::assertSame(5, $saved->getColourSlot());
        self::assertSame('America/Toronto', $saved->getTimezone());
        self::assertSame(CustomerSpaceStatusEnum::Archived, $saved->getStatus());

        // Promoting oneself is the lead's decision, refused under the team field.
        $this->client->jsonRequest('POST', (string) $settings['updatePath'], $this->payload([
            'members' => [
                ['userId' => $this->lead->getId(), 'role' => 'lead'],
                ['userId' => $this->editor->getId(), 'role' => 'lead'],
                ['userId' => $this->reader->getId(), 'role' => 'member'],
            ],
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('members', json_decode((string) $this->client->getResponse()->getContent(), true)['errors']);

        // The lead changes it.
        $this->client->loginUser($this->lead, 'admin');
        $this->client->jsonRequest('POST', (string) $settings['updatePath'], $this->payload([
            'members' => [
                ['userId' => $this->lead->getId(), 'role' => 'lead'],
                ['userId' => $this->editor->getId(), 'role' => 'lead'],
            ],
        ]));
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->reloaded($space)->getMembers());

        $this->client->loginUser($this->reader, 'admin');
        $this->client->jsonRequest('POST', (string) $settings['updatePath'], $this->payload(['name' => 'Rien']));
        self::assertResponseStatusCodeSame(403);
    }

    /** Renaming the space from the tab renames its note space: the sync still runs on update. */
    public function testSavingFromTheTabRenamesTheNoteSpace(): void
    {
        $space = $this->givenSpace();
        $noteSpaceId = (int) self::getContainer()->get(SpaceNoteSpaceProvider::class)->resolve($space)->getId();
        $this->noteSpaces[] = $noteSpaceId;

        [$settings] = $this->tabOf($space);
        $this->client->jsonRequest('POST', (string) $settings['updatePath'], $this->payload([
            'name' => 'Boulangerie Martin - TikTok',
            'members' => [
                ['userId' => $this->lead->getId(), 'role' => 'lead'],
                ['userId' => $this->editor->getId(), 'role' => 'member'],
                ['userId' => $this->reader->getId(), 'role' => 'member'],
            ],
        ]));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('Boulangerie Martin - TikTok', $this->entityManager->find(NoteSpace::class, $noteSpaceId)?->getName());
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client des reglages')->setContractualEmail(bin2hex(random_bytes(4)).'@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', $this->payload([
            'customerId' => $customer->getId(),
            'members' => [
                ['userId' => $this->lead->getId(), 'role' => 'lead'],
                ['userId' => $this->editor->getId(), 'role' => 'member'],
                ['userId' => $this->reader->getId(), 'role' => 'member'],
            ],
        ]));
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find(json_decode((string) $this->client->getResponse()->getContent(), true)['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function payload(array $changes): array
    {
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy(['legalName' => 'Client des reglages']);

        return [
            'name' => 'Boulangerie Martin',
            'customerId' => $customer?->getId(),
            'timezone' => 'Europe/Paris',
            'status' => 'active',
            ...$changes,
        ];
    }

    /**
     * The Settings tab's props as the page renders them: the space form (or
     * null), and whether the reader configures the space.
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function tabOf(CustomerSpace $space): array
    {
        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));
        self::assertResponseIsSuccessful();

        self::assertSame(1, preg_match('/data-symfony--ux-vue--vue-props-value="([^"]*spaceSettings[^"]*)"/', (string) $this->client->getResponse()->getContent(), $match));
        $props = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);

        return [$props['spaceSettings'], $props['canConfigure']];
    }

    private function reloaded(CustomerSpace $space): CustomerSpace
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return $fresh;
    }

    /** @param list<string> $privileges */
    private function user(string $name, array $privileges): User
    {
        $user = new User();
        $user->setEmail(sprintf('reglages-espace-%s-%s@aurora.test', $name, bin2hex(random_bytes(4))))
            ->setName($name)
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges($privileges)
            ->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
