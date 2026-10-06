<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannel;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMember;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261006200000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_filter;
use function base64_decode;
use function bin2hex;
use function class_exists;
use function dirname;
use function file_put_contents;
use function json_decode;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

/**
 * A single visibility rule in a client space.
 *
 * Set on 06/10/2026: everything a space can show the client is born hidden,
 * and showing or hiding it requires the `studio.spaces.share` right, on top
 * of the right to edit. The audit had found six rules; this test holds them
 * together, item by item, so that none drifts off on its own again.
 */
final class SpaceClientVisibilityRuleTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $admin = $container->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        foreach ([
            SpaceFile::class,
            SpaceResource::class,
            DeliverableLink::class,
            Deliverable::class,
            SpaceChatMessage::class,
            SpaceChatChannelMember::class,
            SpaceChatChannel::class,
            SpaceAccessLink::class,
            CustomerSpaceMember::class,
            CustomerSpace::class,
            Customer::class,
        ] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s u WHERE u.email LIKE 'regle-visibilite-%%'", User::class))->execute();

        parent::tearDown();
    }

    /**
     * A new space's board only shows the client the Relecture and Publié
     * steps; an added step is born hidden.
     */
    public function testANewBoardShowsOnlyTheReviewAndPublishedStepsAndANewStepIsHidden(): void
    {
        $space = $this->givenSpace();

        $visible = [];
        foreach ($this->columns($space) as $column) {
            $visible[$column->getRole()?->value ?? $column->getName()] = $column->isVisibleToClient();
        }

        self::assertTrue($visible[SpaceContentColumnRoleEnum::Review->value]);
        self::assertTrue($visible[SpaceContentColumnRoleEnum::Published->value]);
        // The client's calendar keeps what they approved until it goes out:
        // the scheduled step is shown, the two working steps are hidden.
        self::assertCount(2, array_filter($visible, static fn (bool $shown): bool => !$shown), 'the two working steps are hidden');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/create', $space->getId()), ['name' => 'Relecture juridique']);
        self::assertResponseIsSuccessful();

        $added = $this->entityManager->getRepository(SpaceContentColumn::class)->findOneBy(['name' => 'Relecture juridique']);
        self::assertInstanceOf(SpaceContentColumn::class, $added);
        self::assertFalse($added->isVisibleToClient());
    }

    /** Resource, deliverable, channel and file: hidden on creation. */
    public function testEveryOtherElementIsBornHidden(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/create', $space->getId()), ['kind' => 'text', 'label' => 'Consignes', 'body' => 'Court.']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['resources'][0]['visibleToClient']);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => 'Audit']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['deliverables'][0]['visibleToClient']);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/channels/create', $space->getId()), ['name' => 'Entre nous']);
        self::assertResponseIsSuccessful();
        $rooms = [];
        foreach ($this->json()['chatChannels'] as $room) {
            $rooms[$room['name']] = $room;
        }
        self::assertFalse($rooms['Entre nous']['openToClient']);

        $this->upload($space);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['spaceFiles'][0]['visibleToClient']);
    }

    /**
     * The right to edit is no longer enough: every action that shows or hides
     * something from the client answers 403 without the right to share, and
     * edits that do not touch visibility still go through.
     */
    public function testShowingOrHidingNeedsTheRightToShareTheSpace(): void
    {
        $space = $this->givenSpace();
        $sid = $space->getId();

        // Prepared by the administrator, who has every right.
        $review = $this->column($space, SpaceContentColumnRoleEnum::Review);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/create', $sid), ['kind' => 'text', 'label' => 'Consignes', 'body' => 'Court.']);
        $resourceId = (int) $this->json()['resources'][0]['id'];
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $sid), ['title' => 'Audit']);
        $deliverableId = (int) $this->json()['deliverables'][0]['id'];
        $this->upload($space);
        $fileId = (int) $this->json()['spaceFiles'][0]['id'];
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/channels/create', $sid), ['name' => 'Entre nous']);
        $channelId = 0;
        foreach ($this->json()['chatChannels'] as $room) {
            if ('Entre nous' === $room['name']) {
                $channelId = (int) $room['id'];
            }
        }

        $editor = $this->teammate(['studio.spaces.view', 'studio.spaces.edit'], $space);
        $this->client->loginUser($editor, 'admin');

        $refused = [
            'step created shown' => [sprintf('/workspace/%d/columns/create', $sid), ['name' => 'Montrée', 'visibleToClient' => true]],
            'step hidden' => [sprintf('/workspace/%d/columns/%d/update', $sid, $review->getId()), ['name' => $review->getName(), 'role' => 'review', 'visibleToClient' => false]],
            'resource created shown' => [sprintf('/workspace/%d/resources/create', $sid), ['kind' => 'text', 'label' => 'Montrée', 'body' => 'x', 'visibleToClient' => true]],
            'resource shown by its form' => [sprintf('/workspace/%d/resources/%d/update', $sid, $resourceId), ['kind' => 'text', 'label' => 'Consignes', 'body' => 'Court.', 'visibleToClient' => true]],
            'resource toggled' => [sprintf('/workspace/%d/resources/%d/visibility', $sid, $resourceId), []],
            'deliverable toggled' => [sprintf('/workspace/%d/deliverables/%d/visibility', $sid, $deliverableId), ['visible' => true, 'confirm' => true]],
            'deliverable shown by its editor' => [sprintf('/workspace/%d/deliverables/%d/update', $sid, $deliverableId), [...$this->deliverablePayload($deliverableId), 'visibleToClient' => true]],
            'channel created shown' => [sprintf('/workspace/%d/chat/channels/create', $sid), ['name' => 'Montré', 'openToClient' => true]],
            'channel shown' => [sprintf('/workspace/%d/chat/channels/%d/audience', $sid, $channelId), ['openToClient' => true]],
            'file shown' => [sprintf('/workspace/%d/files/%d/visibility', $sid, $fileId), ['visible' => true]],
        ];

        foreach ($refused as $what => [$path, $payload]) {
            $this->client->jsonRequest('POST', $path, $payload);
            self::assertResponseStatusCodeSame(403, $what);
        }

        // What does not touch visibility stays under the right to edit.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/create', $sid), ['name' => 'Cachée']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/update', $sid, $review->getId()), ['name' => 'Relecture du client', 'role' => 'review', 'visibleToClient' => true]);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/chat/channels/create', $sid), ['name' => 'Interne']);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertFalse($this->entityManager->find(SpaceResource::class, $resourceId)?->isVisibleToClient());
        self::assertFalse($this->entityManager->find(Deliverable::class, $deliverableId)?->isVisibleToClient());
        self::assertFalse($this->entityManager->find(SpaceFile::class, $fileId)?->isVisibleToClient());
        self::assertTrue($this->entityManager->find(SpaceContentColumn::class, $review->getId())?->isVisibleToClient());

        // With the right to share, the same action goes through.
        $sharer = $this->teammate(['studio.spaces.view', 'studio.spaces.edit', 'studio.spaces.share'], $space);
        $this->client->loginUser($sharer, 'admin');
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/files/%d/visibility', $sid, $fileId), ['visible' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['spaceFiles'][0]['visibleToClient']);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/%d/visibility', $sid, $resourceId));
        self::assertResponseIsSuccessful();
    }

    /**
     * The client's page does not list a hidden file, and its address does not
     * serve it: hiding the link without closing the route would have hidden
     * nothing.
     */
    public function testThePublicPageNeitherListsNorServesAHiddenFile(): void
    {
        $space = $this->givenSpace();
        $this->upload($space, 'brief-interne.jpg');
        $fileId = (int) $this->json()['spaceFiles'][0]['id'];

        $link = $this->givenLink($space);
        $filePath = sprintf('/spaces/%s/%s/files/%d/file', $link->getSelector(), (string) $link->getPlainToken(), $fileId);

        self::assertStringNotContainsString('brief-interne', $this->clientPage($link));
        $this->client->request('GET', $filePath);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/spaces/%s/%s/files/%d/preview', $link->getSelector(), (string) $link->getPlainToken(), $fileId));
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/files/%d/visibility', $space->getId(), $fileId), ['visible' => true]);
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('brief-interne', $this->clientPage($link));
        $this->client->request('GET', $filePath);
        self::assertResponseIsSuccessful();
    }

    /** What the client sent, they can read: their file cannot be hidden. */
    public function testAFileTheClientSentStaysVisibleToThem(): void
    {
        $space = $this->givenSpace();
        $this->upload($space);
        $fileId = (int) $this->json()['spaceFiles'][0]['id'];

        $file = $this->entityManager->find(SpaceFile::class, $fileId);
        self::assertInstanceOf(SpaceFile::class, $file);
        $file->addedByClient($this->givenLink($space));
        $this->entityManager->flush();
        self::assertTrue($file->isVisibleToClient());

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/files/%d/visibility', $space->getId(), $fileId), ['visible' => false]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Files from before the rule stay visible: the migration writes true on
     * the rows that exist, then false for those to come.
     *
     * Replayed in a transaction on the test database: the column is dropped,
     * the migration runs again, and everything is rolled back at the end.
     */
    public function testTheMigrationKeepsExistingFilesVisibleAndHidesNewOnes(): void
    {
        $space = $this->givenSpace();
        $this->upload($space);
        $fileId = (int) $this->json()['spaceFiles'][0]['id'];

        if (!class_exists(Version20261006200000::class)) {
            require_once dirname(__DIR__, 5).'/migrations/Version20261006200000.php';
        }

        $connection = static::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            $connection->executeStatement('ALTER TABLE core_studio_space_files DROP visible_to_client');

            $migration = new Version20261006200000($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement());
            }

            self::assertTrue((bool) $connection->fetchOne('SELECT visible_to_client FROM core_studio_space_files WHERE id = :id', ['id' => $fileId]), 'an existing file stays visible');
            self::assertSame('false', (string) $connection->fetchOne(
                "SELECT column_default FROM information_schema.columns WHERE table_name = 'core_studio_space_files' AND column_name = 'visible_to_client'",
            ), 'a new file is hidden');
            self::assertSame('false', (string) $connection->fetchOne(
                "SELECT column_default FROM information_schema.columns WHERE table_name = 'core_studio_space_content_columns' AND column_name = 'visible_to_client'",
            ), 'a new step is hidden');
        } finally {
            $connection->rollBack();
        }
    }

    /** @return list<SpaceContentColumn> */
    private function columns(CustomerSpace $space): array
    {
        $this->entityManager->clear();

        /** @var list<SpaceContentColumn> $columns */
        $columns = static::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace(
            $this->entityManager->find(CustomerSpace::class, $space->getId()),
        );

        return $columns;
    }

    private function column(CustomerSpace $space, SpaceContentColumnRoleEnum $role): SpaceContentColumn
    {
        foreach ($this->columns($space) as $column) {
            if ($role === $column->getRole()) {
                return $column;
            }
        }

        self::fail('no step with that role');
    }

    /** @return array<string, mixed> */
    private function deliverablePayload(int $id): array
    {
        $this->entityManager->clear();
        $entity = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $entity);

        return [
            'title' => $entity->getTitle(),
            'summary' => $entity->getSummary(),
            'locale' => $entity->getLocale(),
            'gridLayout' => $entity->getGridLayout(),
            'gridContent' => $entity->getGridContent(),
            'appearance' => $entity->getAppearance(),
            'readingHeader' => $entity->getReadingHeader(),
            'visibleToClient' => $entity->isVisibleToClient(),
        ];
    }

    /** @param list<string> $privileges */
    private function teammate(array $privileges, CustomerSpace $space): User
    {
        $user = new User();
        $user->setEmail(sprintf('regle-visibilite-%s@example.test', bin2hex(random_bytes(4))))->setName('Équipier')
            ->setType(UserTypeEnum::Suite)->setRoles([UserRoleEnum::User->value])->setPassword('x')
            ->setPrivileges($privileges);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $member = new CustomerSpaceMember();
        $member->setSpace($this->entityManager->find(CustomerSpace::class, $space->getId()))
            ->setUser($user)
            ->setRole(CustomerSpaceMemberRoleEnum::Member);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $user;
    }

    private function givenLink(CustomerSpace $space): SpaceAccessLinkInterface
    {
        $fresh = $this->entityManager->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return static::getContainer()->get(SpaceAccessLinkManagerInterface::class)->issue($fresh, 'client@example.test', 'Le client', 30, true, true);
    }

    private function clientPage(SpaceAccessLinkInterface $link): string
    {
        $this->client->request('GET', sprintf('/spaces/%s/%s', $link->getSelector(), (string) $link->getPlainToken()));
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function upload(CustomerSpace $space, string $name = 'charte.jpg'): void
    {
        $path = sys_get_temp_dir().'/aurora-visibility-'.bin2hex(random_bytes(4)).'.jpg';
        file_put_contents($path, (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        ));
        $this->temporaryFiles[] = $path;

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/files/upload', $space->getId()),
            [],
            ['file' => new UploadedFile($path, $name, 'image/jpeg', null, true)],
        );
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client de la règle')->setSiret('73282932000074')->setContractualEmail('regle@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace de la règle',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->find(CustomerSpace::class, $this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
