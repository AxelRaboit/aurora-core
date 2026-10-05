<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Message\PurgeTrashedDeliverablesMessage;
use Aurora\Module\Studio\Deliverable\MessageHandler\PurgeTrashedDeliverablesHandler;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverableDocumentUsageProvider;
use Aurora\Module\Studio\Deliverable\Trash\DeliverablesTrashSource;
use Aurora\Module\Studio\Search\StudioSuiteSearchProvider;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function basename;
use function bin2hex;
use function json_decode;
use function parse_url;
use function random_bytes;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * Deliverables in the common trash.
 *
 * A deliverable is a client document written by hand: destroying an audit by
 * mistake was final, with no trace of who did it. Deleting now moves it to the
 * trash, where it leaves the lists, the search, the counts and the reading
 * links, keeps its pictures counted and its links in place, and comes back
 * whole on a restore. The scheduled purge, after the delay every trash shares,
 * is the only thing that destroys one besides the button.
 */
final class DeliverableTrashTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private const array TEAM = ['studio.deliverables.view', 'studio.deliverables.create', 'studio.deliverables.edit', 'studio.deliverables.delete', 'studio.deliverables.share'];

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    /** @var list<int> */
    private array $users = [];

    /** @var list<int> */
    private array $documents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');

        $this->resetRateLimiter('deliverable_password');
    }

    protected function tearDown(): void
    {
        foreach ([DeliverableLink::class, Deliverable::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType IN ('Deliverable', 'DeliverableLink')", AuditLog::class))->execute();

        foreach ($this->documents as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s d WHERE d.id = :id', Document::class))->setParameter('id', $id)->execute();
        }

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    public function testDeletingMovesTheDeliverableToTheTrashInsteadOfDestroyingIt(): void
    {
        $id = $this->createStudio('Audit à jeter', DeliverableScopeEnum::Shared);

        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $id));
        self::assertResponseIsSuccessful();

        $stored = $this->find($id);
        self::assertTrue($stored->isTrashed());
        self::assertNotNull($stored->getDeletedAt());

        // It left the list, and cannot be opened, edited, previewed or sent any more.
        self::assertNotContains($id, array_column($this->json()['shared'], 'id'));
        foreach (['GET' => ['', ''], 'POST' => ['/update', '/duplicate']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $this->client->request($method, sprintf('/suite/studio/deliverables/%d%s', $id, $suffix));
                self::assertResponseStatusCodeSame(404, sprintf('%s %s', $method, $suffix));
            }
        }
        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/preview', $id));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/links', $id));
        self::assertResponseStatusCodeSame(404);
    }

    public function testItsReadingLinksStopAnsweringAndResumeOnRestore(): void
    {
        $id = $this->createStudio('Avec un lien', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/suite/studio/deliverables/%d/links/create', $id), ['label' => 'Pour Léa']);
        $token = basename((string) parse_url($this->json()['links'][0]['url'], PHP_URL_PATH));

        $this->client->request('GET', sprintf('/deliverables/%s', $token));
        self::assertResponseIsSuccessful();

        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $id));
        $this->client->request('GET', sprintf('/deliverables/%s', $token));
        self::assertResponseStatusCodeSame(404);

        // The link and its history are still there: only the answer stopped.
        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(DeliverableLink::class)->findAll());

        $this->post(sprintf('/suite/studio/deliverables/%d/restore', $id));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->find($id)->isTrashed());

        $this->client->request('GET', sprintf('/deliverables/%s', $token));
        self::assertResponseIsSuccessful();
    }

    public function testATrashedDeliverableCannotBeUnlockedEither(): void
    {
        $id = $this->createStudio('Verrouillé et jeté', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/suite/studio/deliverables/%d/links/create', $id), ['password' => 'secret']);
        $token = basename((string) parse_url($this->json()['links'][0]['url'], PHP_URL_PATH));
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $id));

        $this->client->request('POST', sprintf('/deliverables/%s/unlock', $token), ['password' => 'secret']);

        // A wrong answer, not a redirect to a document that is gone.
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/deliverables/'.$token, (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testItLeavesTheSearchTheDashboardCountAndTheSpaceBoard(): void
    {
        $needle = 'Cannelle'.bin2hex(random_bytes(3));
        $id = $this->createStudio('Trouvable '.$needle, DeliverableScopeEnum::Shared);
        $space = $this->givenSpace();
        $inSpace = $this->createInSpace($space, 'Dans l\'espace '.$needle);

        $search = self::getContainer()->get(StudioSuiteSearchProvider::class);
        self::assertCount(2, $search->search($needle)['deliverables']);
        $repository = self::getContainer()->get(DeliverableRepository::class);
        $before = $repository->countStandaloneFor($this->admin);

        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $id));
        $this->post(sprintf('/workspace/%d/deliverables/%d/delete', $space->getId(), $inSpace));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['deliverables']);

        self::assertSame([], $search->search($needle)['deliverables']);
        self::assertSame($before - 1, $repository->countStandaloneFor($this->admin));
    }

    public function testTheClientNoLongerSeesATrashedSpaceDeliverable(): void
    {
        $space = $this->givenSpace();
        $id = $this->createInSpace($space, 'Visible puis jeté');
        $this->post(sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $id), ['visible' => true]);
        self::assertResponseIsSuccessful();

        $access = self::getContainer()->get(SpaceAccessLinkManagerInterface::class)
            ->issue($this->entityManager->find(CustomerSpace::class, $space->getId()), 'client@example.test', 'Le client', 30, true, true);
        $url = sprintf('/spaces/%s/%s/deliverables/%d', $access->getSelector(), (string) $access->getPlainToken(), $id);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->post(sprintf('/workspace/%d/deliverables/%d/delete', $space->getId(), $id));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->post(sprintf('/workspace/%d/deliverables/%d/restore', $space->getId(), $id));
        // Restoring goes through Studio's route: it answers for a space's deliverable too.
        $this->post(sprintf('/suite/studio/deliverables/%d/restore', $id));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testRestoreAndDestroyAreAskedOfTheRowsOwnRules(): void
    {
        $personal = $this->createStudio('Perso', DeliverableScopeEnum::Personal);
        $shared = $this->createStudio('Partagé', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $personal));
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $shared));

        // A colleague does not even see another's personal one: 404, not 403.
        $reader = $this->accountWith(['studio.deliverables.view']);
        $this->client->loginUser($reader, 'admin');
        $this->post(sprintf('/suite/studio/deliverables/%d/restore', $personal));
        self::assertResponseStatusCodeSame(404);

        // A reader sees the shared one in the trash, and may neither restore nor destroy it.
        $this->post(sprintf('/suite/studio/deliverables/%d/restore', $shared));
        self::assertResponseStatusCodeSame(403);
        $this->post(sprintf('/suite/studio/deliverables/%d/force-delete', $shared));
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->find($shared)->isTrashed());

        // The team can restore it, but destroying wants the right to delete.
        $editor = $this->accountWith(['studio.deliverables.view', 'studio.deliverables.edit']);
        $this->client->loginUser($editor, 'admin');
        $this->post(sprintf('/suite/studio/deliverables/%d/force-delete', $shared));
        self::assertResponseStatusCodeSame(403);
        $this->post(sprintf('/suite/studio/deliverables/%d/restore', $shared));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->find($shared)->isTrashed());
    }

    public function testDestroyingForGoodRemovesItAndItsLinks(): void
    {
        $id = $this->createStudio('À détruire', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/suite/studio/deliverables/%d/links/create', $id), []);
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $id));

        $this->post(sprintf('/suite/studio/deliverables/%d/force-delete', $id));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Deliverable::class, $id));
        self::assertSame([], $this->entityManager->getRepository(DeliverableLink::class)->findAll());

        // Nothing left to destroy: the same answer as an identifier that never existed.
        $this->post(sprintf('/suite/studio/deliverables/%d/force-delete', $id));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAliveOnesCannotBeRestoredOrDestroyedThroughTheTrashRoutes(): void
    {
        $id = $this->createStudio('Bien vivant', DeliverableScopeEnum::Shared);

        $this->post(sprintf('/suite/studio/deliverables/%d/restore', $id));
        self::assertResponseStatusCodeSame(404);
        $this->post(sprintf('/suite/studio/deliverables/%d/force-delete', $id));
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->find($id)->isTrashed());
    }

    public function testEmptyingTheTrashDestroysOnlyWhatTheReaderMayDestroy(): void
    {
        $mine = $this->createStudio('À moi', DeliverableScopeEnum::Personal);
        $shared = $this->createStudio('À tous', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $mine));
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $shared));

        // An editor without the delete right empties nothing, and above all not another's personal one.
        $editor = $this->accountWith(['studio.deliverables.view', 'studio.deliverables.edit']);
        $this->client->loginUser($editor, 'admin');
        $this->post('/suite/studio/deliverables/empty-trash');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['deleted']);

        // A member with the right destroys the shared one, never the author's personal one.
        $deleter = $this->accountWith(self::TEAM);
        $this->client->loginUser($deleter, 'admin');
        $this->post('/suite/studio/deliverables/empty-trash');
        self::assertSame(1, $this->json()['deleted']);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Deliverable::class, $shared));
        self::assertNotNull($this->entityManager->find(Deliverable::class, $mine));
    }

    public function testTheTrashScreenListsWhatTheReaderMayOpen(): void
    {
        $mine = $this->createStudio('Mon brouillon', DeliverableScopeEnum::Personal);
        $shared = $this->createStudio('Pour l\'équipe', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $mine));
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $shared));

        $source = self::getContainer()->get(DeliverablesTrashSource::class);

        $this->client->loginUser($this->admin, 'admin');
        $summary = $source->getSummary(10);
        self::assertInstanceOf(TrashSummary::class, $summary);
        self::assertSame(2, $summary->count);
        self::assertEqualsCanonicalizing(['Mon brouillon', 'Pour l\'équipe'], array_column($summary->items, 'label'));
        self::assertSame('suite_studio_deliverables_restore', $summary->restoreRoute);

        // A teammate sees the shared one and nothing of the personal one, not even its count.
        $this->client->loginUser($this->accountWith(self::TEAM), 'admin');
        $teammate = $source->getSummary(10);
        self::assertSame(1, $teammate->count);
        self::assertSame(['Pour l\'équipe'], array_column($teammate->items, 'label'));

        // And the common trash screen carries it.
        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', '/suite/trash/list');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('studio_deliverables', (string) $this->client->getResponse()->getContent());
    }

    public function testATrashedPictureIsStillCountedByTheLibrary(): void
    {
        $picture = $this->givenImage();
        $id = $this->createStudio('Avec image', DeliverableScopeEnum::Shared);
        $this->entityManager->clear();
        $stored = $this->entityManager->find(Deliverable::class, $id);
        $stored->setGridLayout([...$stored->getGridLayout(), 'enabled' => true, 'zones' => [['id' => 'p', 'type' => 'items', 'items' => [['mediaId' => $picture]]]]]);
        $this->entityManager->flush();

        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $id));

        $provider = self::getContainer()->get(DeliverableDocumentUsageProvider::class);
        // Not released until the purge: a restore must find the picture where it was.
        self::assertSame([$picture => 1], $provider->countUsagesFor([$picture]));
        $usages = $provider->findUsages($picture);
        self::assertCount(1, $usages);
        self::assertNull($usages[0]['href']);
    }

    public function testThePurgeDestroysOnlyWhatHasBeenInTheTrashLongEnough(): void
    {
        $settings = self::getContainer()->get(SettingRepository::class);
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, '30');

        $old = $this->createStudio('Jeté il y a longtemps', DeliverableScopeEnum::Shared);
        $recent = $this->createStudio('Jeté hier', DeliverableScopeEnum::Shared);
        $alive = $this->createStudio('Toujours là', DeliverableScopeEnum::Shared);
        $this->trashedAt($old, new DateTimeImmutable('-45 days'));
        $this->trashedAt($recent, new DateTimeImmutable('-1 day'));

        self::getContainer()->get(PurgeTrashedDeliverablesHandler::class)(new PurgeTrashedDeliverablesMessage());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Deliverable::class, $old));
        self::assertNotNull($this->entityManager->find(Deliverable::class, $recent));
        self::assertNotNull($this->entityManager->find(Deliverable::class, $alive));
    }

    public function testThePurgeDoesNothingWhenRetentionIsSwitchedOff(): void
    {
        $settings = self::getContainer()->get(SettingRepository::class);
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, '0');
        $old = $this->createStudio('Jeté, jamais purgé', DeliverableScopeEnum::Shared);
        $this->trashedAt($old, new DateTimeImmutable('-400 days'));

        self::getContainer()->get(PurgeTrashedDeliverablesHandler::class)(new PurgeTrashedDeliverablesMessage());

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Deliverable::class, $old));
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue());
    }

    public function testTrashingAndRestoringAreInTheAuditLog(): void
    {
        $id = $this->createStudio('Tracé', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/suite/studio/deliverables/%d/delete', $id));
        $this->post(sprintf('/suite/studio/deliverables/%d/restore', $id));

        $this->entityManager->clear();
        $actions = array_map(
            static fn (AuditLog $log): string => $log->getAction(),
            $this->entityManager->getRepository(AuditLog::class)->findBy(['entityType' => 'Deliverable', 'entityId' => $id]),
        );

        self::assertContains('deliverable.trashed', $actions);
        self::assertContains('deliverable.restored', $actions);
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body = []): void
    {
        $this->client->jsonRequest('POST', $uri, $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function createStudio(string $title, DeliverableScopeEnum $scope): int
    {
        $this->post('/suite/studio/deliverables/create', ['title' => $title, 'scope' => $scope->value]);
        self::assertResponseIsSuccessful();

        foreach ($this->json()[$scope->value] as $row) {
            if ($row['title'] === $title) {
                return (int) $row['id'];
            }
        }

        self::fail(sprintf('Le livrable « %s » n\'est pas revenu dans son rayon.', $title));
    }

    private function createInSpace(CustomerSpace $space, string $title): int
    {
        $this->post(sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => $title]);
        self::assertResponseIsSuccessful();

        foreach ($this->json()['deliverables'] as $row) {
            if ($row['title'] === $title) {
                return (int) $row['id'];
            }
        }

        self::fail(sprintf('Le livrable « %s » n\'est pas revenu dans la liste.', $title));
    }

    /** @return array{personal: list<array<string, mixed>>, shared: list<array<string, mixed>>} */
    private function lists(): array
    {
        $this->client->request('GET', '/suite/studio/deliverables/lists');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function find(int $id): Deliverable
    {
        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $deliverable);

        return $deliverable;
    }

    private function trashedAt(int $id, DateTimeImmutable $at): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s d SET d.deletedAt = :at WHERE d.id = :id', Deliverable::class))
            ->setParameter('at', $at)
            ->setParameter('id', $id)
            ->execute();
    }

    private function givenImage(): int
    {
        $name = 'corbeille-'.bin2hex(random_bytes(4)).'.jpg';
        $document = new Document();
        $document->setTitle($name)->setFilePath('ged/2026/10/'.$name)->setFileName($name)->setOriginalName($name)->setMimeType('image/jpeg')->setSize(1)->setStatus(DocumentStatusEnum::Published);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = (int) $document->getId();

        return (int) $document->getId();
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('corbeille-livrables-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Équipier '.bin2hex(random_bytes(2)))
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPassword('irrelevant')
            ->setPrivileges($privileges);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client Corbeille')->setContractualEmail('corbeille-livrables@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin, 'admin');
        $this->post('/suite/studio/spaces/create', ['name' => 'Espace corbeille', 'customerId' => $customer->getId(), 'timezone' => 'Europe/Paris']);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
