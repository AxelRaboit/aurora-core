<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Post\Grid\ZoneSiteViews;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\Slide;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Message\PurgeTrashedDecksMessage;
use Aurora\Module\Studio\Deck\MessageHandler\PurgeTrashedDecksHandler;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deck\Service\DeckDocumentUsageProvider;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLink;
use Aurora\Module\Studio\Deck\Trash\DecksTrashSource;
use Aurora\Module\Studio\Search\StudioSuiteSearchProvider;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Presentations in the common trash.
 *
 * Deleting a deck used to destroy it, slides and share links with it. It now
 * moves to the trash: it leaves the lists, the search and the counts, every
 * route that takes it answers 404, its share links stop answering and resume
 * on a restore. Only the force-delete button or the scheduled purge destroys
 * one, and both need the right that put it there.
 */
final class DeckTrashTest extends IntegrationTestCase
{
    private const int PICTURE = 2_000_000_007;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    /** @var list<int> */
    private array $users = [];

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
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', DeckShareLink::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Slide::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deck::class))->execute();

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    public function testDeletingMovesTheDeckToTheTrashInsteadOfDestroyingIt(): void
    {
        $id = $this->createDeck('Réunion de lancement');

        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));
        self::assertResponseIsSuccessful();

        $stored = $this->find($id);
        self::assertTrue($stored->isTrashed());
        self::assertCount(1, $stored->getSlides(), 'The slides are kept: nothing is destroyed.');
    }

    public function testEveryRouteThatTakesTheDeckAnswers404WhileItIsTrashed(): void
    {
        $id = $this->createDeck('Hors de vue');
        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));

        foreach (['', '/print', '/presenter'] as $suffix) {
            $this->client->request('GET', sprintf('/suite/studio/decks/%d%s', $id, $suffix));
            self::assertResponseStatusCodeSame(404, 'GET '.$suffix);
        }

        foreach (['/update', '/duplicate', '/appearance', '/slides/create', '/share/create'] as $suffix) {
            $this->post(sprintf('/suite/studio/decks/%d%s', $id, $suffix));
            self::assertResponseStatusCodeSame(404, 'POST '.$suffix);
        }

        // Nothing was written under its feet.
        self::assertSame(0, $this->entityManager->getRepository(DeckShareLink::class)->count([]));
    }

    public function testItLeavesTheListTheSearchAndTheCount(): void
    {
        $needle = 'Cannelle'.bin2hex(random_bytes(3));
        $id = $this->createDeck('Trouvable '.$needle);
        $repository = self::getContainer()->get(DeckRepository::class);
        $search = self::getContainer()->get(StudioSuiteSearchProvider::class);

        self::assertCount(1, $search->search($needle)['decks']);
        $before = $repository->countLive();

        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));

        self::assertSame([], $search->search($needle)['decks']);
        self::assertSame($before - 1, $repository->countLive());
        self::assertNotContains($id, array_map(static fn ($deck): ?int => $deck->getId(), $repository->findAllForList()));
    }

    public function testItsShareLinksStopAnsweringAndResumeOnRestore(): void
    {
        $id = $this->createDeck('Avec un lien');
        $token = $this->shareLink($id);

        $this->client->request('GET', '/decks/'.$token);
        self::assertResponseIsSuccessful();

        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));
        $this->client->request('GET', '/decks/'.$token);
        self::assertResponseStatusCodeSame(404);

        // The link and its history are still there.
        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->getRepository(DeckShareLink::class)->count([]));

        $this->post(sprintf('/suite/studio/decks/%d/restore', $id));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->find($id)->isTrashed());

        $this->client->request('GET', '/decks/'.$token);
        self::assertResponseIsSuccessful();
    }

    public function testATrashedDeckCannotBeUnlocked(): void
    {
        $id = $this->createDeck('Verrouillé et jeté');
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $id), ['password' => 'verysecure123']);
        $this->entityManager->clear();
        $link = $this->entityManager->getRepository(DeckShareLink::class)->findOneBy([]);
        $token = $link->getToken();
        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));

        $this->client->request('POST', sprintf('/decks/%s/unlock', $token), ['password' => 'verysecure123']);

        // The page that says the password is wrong, not a redirect to a deck that is gone.
        self::assertResponseIsSuccessful();
        self::assertEmpty($this->client->getResponse()->headers->get('Location'));
    }

    public function testRestoreAndDestroyNeedTheRightToDelete(): void
    {
        $id = $this->createDeck('Droits');
        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));

        $editor = $this->accountWith(['studio.decks.view', 'studio.decks.edit', 'studio.decks.create']);
        $this->client->loginUser($editor, 'admin');

        foreach (['/restore', '/force-delete'] as $suffix) {
            $this->post(sprintf('/suite/studio/decks/%d%s', $id, $suffix));
            self::assertResponseStatusCodeSame(403, $suffix);
        }
        $this->post('/suite/studio/decks/empty-trash');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->find($id)->isTrashed());

        $this->client->loginUser($this->accountWith(['studio.decks.view', 'studio.decks.delete']), 'admin');
        $this->post(sprintf('/suite/studio/decks/%d/restore', $id));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->find($id)->isTrashed());
    }

    public function testDestroyingForGoodRemovesTheDeckItsSlidesAndItsLinks(): void
    {
        $id = $this->createDeck('À détruire');
        $this->shareLink($id);
        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));

        $this->post(sprintf('/suite/studio/decks/%d/force-delete', $id));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Deck::class, $id));
        self::assertSame(0, $this->entityManager->getRepository(DeckShareLink::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(Slide::class)->count([]));

        $this->post(sprintf('/suite/studio/decks/%d/force-delete', $id));
        self::assertResponseStatusCodeSame(404);
    }

    public function testALiveDeckCannotBeRestoredOrDestroyedThroughTheTrashRoutes(): void
    {
        $id = $this->createDeck('Bien vivant');

        $this->post(sprintf('/suite/studio/decks/%d/restore', $id));
        self::assertResponseStatusCodeSame(404);
        $this->post(sprintf('/suite/studio/decks/%d/force-delete', $id));
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->find($id)->isTrashed());
    }

    public function testEmptyingTheTrashDestroysOnlyWhatIsInIt(): void
    {
        $alive = $this->createDeck('Vivant');
        $first = $this->createDeck('Premier jeté');
        $second = $this->createDeck('Second jeté');
        $this->post(sprintf('/suite/studio/decks/%d/delete', $first));
        $this->post(sprintf('/suite/studio/decks/%d/delete', $second));

        $this->post('/suite/studio/decks/empty-trash');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['deleted']);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Deck::class, $first));
        self::assertNull($this->entityManager->find(Deck::class, $second));
        self::assertNotNull($this->entityManager->find(Deck::class, $alive));
    }

    public function testTheTrashScreenListsThemForWhoMayViewDecks(): void
    {
        $id = $this->createDeck('Dans la corbeille');
        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));
        $source = self::getContainer()->get(DecksTrashSource::class);

        $summary = $source->getSummary(10);
        self::assertInstanceOf(TrashSummary::class, $summary);
        self::assertSame('studio_decks', $summary->key);
        self::assertSame(1, $summary->count);
        self::assertSame(['Dans la corbeille'], array_column($summary->items, 'label'));
        self::assertSame('suite_studio_decks_restore', $summary->restoreRoute);
        self::assertSame('studio.decks.delete', $summary->actionPrivilege);
        self::assertSame('studio.decks.view', $source->getRequiredPrivilege());

        $this->client->request('GET', '/suite/trash/list');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('studio_decks', (string) $this->client->getResponse()->getContent());
    }

    public function testATrashedPictureIsStillCountedByTheLibraryUntilThePurge(): void
    {
        $id = $this->createDeck('Avec image', self::PICTURE);
        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));

        $provider = self::getContainer()->get(DeckDocumentUsageProvider::class);

        self::assertSame([self::PICTURE => 1], $provider->countUsagesFor([self::PICTURE]));
        $usages = $provider->findUsages(self::PICTURE);
        self::assertCount(1, $usages);
        self::assertNull($usages[0]['href'], 'Nothing to open from the trash.');
    }

    public function testThePurgeDestroysOnlyWhatHasBeenInTheTrashLongEnough(): void
    {
        $settings = self::getContainer()->get(SettingRepository::class);
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, '30');

        $old = $this->createDeck('Jeté il y a longtemps');
        $recent = $this->createDeck('Jeté hier');
        $alive = $this->createDeck('Toujours là');
        $this->trashedAt($old, new DateTimeImmutable('-45 days'));
        $this->trashedAt($recent, new DateTimeImmutable('-1 day'));

        self::getContainer()->get(PurgeTrashedDecksHandler::class)(new PurgeTrashedDecksMessage());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Deck::class, $old));
        self::assertNotNull($this->entityManager->find(Deck::class, $recent));
        self::assertNotNull($this->entityManager->find(Deck::class, $alive));
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue());
    }

    public function testThePurgeDoesNothingWhenRetentionIsSwitchedOff(): void
    {
        $settings = self::getContainer()->get(SettingRepository::class);
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, '0');
        $old = $this->createDeck('Jeté, jamais purgé');
        $this->trashedAt($old, new DateTimeImmutable('-400 days'));

        self::getContainer()->get(PurgeTrashedDecksHandler::class)(new PurgeTrashedDecksMessage());

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Deck::class, $old));
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue());
    }

    public function testAPublicationNoLongerShowsATrashedDeck(): void
    {
        $id = $this->createDeck('Dans une publication');
        $this->shareLink($id);
        $views = self::getContainer()->get(ZoneSiteViews::class);

        self::assertNotNull($views->deckView($id), 'The control: a live deck with an open link is shown.');

        $this->post(sprintf('/suite/studio/decks/%d/delete', $id));
        self::assertNull($views->deckView($id));

        $this->post(sprintf('/suite/studio/decks/%d/restore', $id));
        self::assertNotNull($views->deckView($id));
    }

    public function testATrashedModelIsNotCopiedFrom(): void
    {
        $live = $this->createDeck('Modèle vivant');
        $trashed = $this->createDeck('Modèle jeté');
        $this->post(sprintf('/suite/studio/decks/%d/delete', $trashed));

        // The control: a live model's slides are copied into the new deck.
        $this->post('/suite/studio/decks/create', ['title' => 'Copie du vivant', 'fromTemplateId' => $live]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['deck']['slides']);

        // An unknown id opens an empty deck rather than failing, and a trashed one is unknown.
        $this->post('/suite/studio/decks/create', ['title' => 'Copie du jeté', 'fromTemplateId' => $trashed]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->json()['deck']['slides']);
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body = []): void
    {
        $this->client->jsonRequest('POST', $uri, $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createDeck(string $title, ?int $picture = null): int
    {
        $deck = new Deck();
        $deck->setTitle($title);
        $slide = new Slide();
        $slide->setLayout(SlideLayoutEnum::cases()[0])->setContent(null === $picture ? [] : ['mediaId' => $picture])->setPosition(0);
        $deck->addSlide($slide);
        $this->entityManager->persist($deck);
        $this->entityManager->persist($slide);
        $this->entityManager->flush();

        return (int) $deck->getId();
    }

    private function shareLink(int $deck): string
    {
        $this->post(sprintf('/suite/studio/decks/%d/share/create', $deck));
        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $link = $this->entityManager->getRepository(DeckShareLink::class)->findOneBy([]);

        return $link->getToken();
    }

    private function find(int $id): Deck
    {
        $this->entityManager->clear();
        $deck = $this->entityManager->find(Deck::class, $id);
        self::assertInstanceOf(Deck::class, $deck);

        return $deck;
    }

    private function trashedAt(int $id, DateTimeImmutable $at): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s d SET d.deletedAt = :at WHERE d.id = :id', Deck::class))
            ->setParameter('at', $at)
            ->setParameter('id', $id)
            ->execute();
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('corbeille-decks-'.bin2hex(random_bytes(5)).'@aurora.app')
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
}
