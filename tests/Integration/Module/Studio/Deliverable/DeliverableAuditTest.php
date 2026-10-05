<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

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
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverableDocumentUsageProvider;
use Aurora\Module\Studio\Search\StudioBackendSearchProvider;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function basename;
use function bin2hex;
use function json_decode;
use function json_encode;
use function parse_url;
use function random_bytes;
use function sprintf;
use function str_repeat;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_PATH;

/**
 * What the audit of the deliverables module found, one test per defect.
 *
 * The permission and validation rules here were each missing before: the list
 * of reading links answered anybody who could read the document, a space's
 * links asked for a weaker privilege than the space's own access, a link
 * expiry nobody could parse became a link that never expires, a missing scope
 * turned a shared deliverable personal, and two people saving the same
 * document overwrote each other without a word.
 */
final class DeliverableAuditTest extends IntegrationTestCase
{
    use ResetsRateLimiters;
    /** The shared test password of the project: a named constant, never a new literal. */
    private const string OTHER_PHRASE = 'verysecure123';

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

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
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

    /** The list carries the addresses themselves, tokens included: reading it is sharing them. */
    public function testTheLinksOfAStudioDeliverableAreForWhoMayShareIt(): void
    {
        $id = $this->createStudio('Audit partagé', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), ['label' => 'Pour Jean']);
        self::assertResponseIsSuccessful();

        $reader = $this->accountWith(['studio.deliverables.view']);
        $this->client->loginUser($reader, 'admin');
        $this->client->request('GET', sprintf('/backend/studio/deliverables/%d/links', $id));
        self::assertResponseStatusCodeSame(403);

        $sharer = $this->accountWith(self::TEAM);
        $this->client->loginUser($sharer, 'admin');
        $this->client->request('GET', sprintf('/backend/studio/deliverables/%d/links', $id));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['links']);
    }

    public function testASpacesLinksNeedTheRightToShareTheSpace(): void
    {
        $space = $this->givenSpace();
        $id = $this->givenSpaceDeliverable($space, 'Audit client');

        // View and edit, no share: the right the space's own access link asks for.
        $editor = $this->accountWith(['studio.spaces.view', 'studio.spaces.edit']);
        $this->membership($space, $editor);
        $this->client->loginUser($editor, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/links', $space->getId(), $id));
        self::assertResponseStatusCodeSame(403);
        $this->post(sprintf('/workspace/%d/deliverables/%d/links/create', $space->getId(), $id), []);
        self::assertResponseStatusCodeSame(403);

        // Share, and nothing to write: giving an address is not editing the document.
        $sharer = $this->accountWith(['studio.spaces.view', 'studio.spaces.share']);
        $this->membership($space, $sharer);
        $this->client->loginUser($sharer, 'admin');

        $this->post(sprintf('/workspace/%d/deliverables/%d/links/create', $space->getId(), $id), ['label' => 'Pour le client']);
        self::assertResponseIsSuccessful();
        $link = $this->json()['links'][0];

        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/links', $space->getId(), $id));
        self::assertResponseIsSuccessful();

        $this->post(sprintf('/workspace/%d/deliverables/%d/links/%d/revoke', $space->getId(), $id, $link['id']), []);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidLinkSettings(): iterable
    {
        yield 'a fraction of a day' => [['expiresInDays' => 7.5], 'expiresInDays'];
        yield 'a number written as text' => [['expiresInDays' => '30'], 'expiresInDays'];
        yield 'zero days' => [['expiresInDays' => 0], 'expiresInDays'];
        yield 'a negative duration' => [['expiresInDays' => -3], 'expiresInDays'];
        yield 'more than a year' => [['expiresInDays' => 400], 'expiresInDays'];
        yield 'a password longer than bcrypt reads' => [['password' => str_repeat('é', 40)], 'password'];
    }

    /**
     * A duration nobody could read used to become a link that never expires:
     * the opposite of what typing a duration meant.
     *
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidLinkSettings')]
    public function testAnUnreadableLinkSettingIsRefusedNotIgnored(array $settings, string $field): void
    {
        $id = $this->createStudio('Audit', DeliverableScopeEnum::Shared);

        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), $settings);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey($field, $this->json()['errors']);
        self::assertSame([], $this->entityManager->getRepository(DeliverableLink::class)->findAll());
    }

    public function testALinkWithoutDurationNeverExpiresAndOneWithADurationDoes(): void
    {
        $id = $this->createStudio('Audit', DeliverableScopeEnum::Shared);

        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), []);
        self::assertResponseIsSuccessful();
        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), ['expiresInDays' => 30]);
        self::assertResponseIsSuccessful();

        $expiries = array_column($this->json()['links'], 'expiresAt');
        self::assertCount(2, $expiries);
        self::assertContains(null, $expiries);
        self::assertCount(1, array_filter($expiries));
    }

    /** Giving or withdrawing an address opens or closes access to a client document: one line each. */
    public function testIssuingAndRevokingALinkAreWrittenToTheAuditLog(): void
    {
        $id = $this->createStudio('Audit tracé', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), ['label' => 'Pour Léa', 'password' => 'secret phrase']);
        $linkId = $this->json()['links'][0]['id'];
        $this->post(sprintf('/backend/studio/deliverables/%d/links/%d/revoke', $id, $linkId), []);

        $actions = $this->auditActions();
        self::assertContains('deliverable.created', $actions);
        self::assertContains('deliverable_link.issued', $actions);
        self::assertContains('deliverable_link.revoked', $actions);

        // The line says who and which document, and never carries the address or the password.
        $logs = $this->entityManager->getRepository(AuditLog::class)->findBy(['entityType' => 'DeliverableLink']);
        foreach ($logs as $log) {
            $data = (string) json_encode($log->getData(), JSON_UNESCAPED_UNICODE);
            self::assertStringContainsString('Audit tracé', $data);
            self::assertStringNotContainsString('secret phrase', $data);
            self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $data);
        }
    }

    public function testDeletingAndCopyingADeliverableAreWrittenToTheAuditLog(): void
    {
        $id = $this->createStudio('Éphémère', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/backend/studio/deliverables/%d/duplicate', $id), []);
        self::assertResponseIsSuccessful();
        $this->post(sprintf('/backend/studio/deliverables/%d/delete', $id), []);
        self::assertResponseIsSuccessful();
        $this->post(sprintf('/backend/studio/deliverables/%d/restore', $id), []);
        self::assertResponseIsSuccessful();
        $this->post(sprintf('/backend/studio/deliverables/%d/delete', $id), []);
        $this->post(sprintf('/backend/studio/deliverables/%d/force-delete', $id), []);
        self::assertResponseIsSuccessful();

        $actions = $this->auditActions();
        self::assertContains('deliverable.duplicated', $actions);
        self::assertContains('deliverable.trashed', $actions);
        self::assertContains('deliverable.restored', $actions);
        self::assertContains('deliverable.deleted', $actions);
    }

    /** « perso » is not the default of a gesture that withdraws the document from the team. */
    public function testAMalformedScopeChangeLeavesASharedDeliverableShared(): void
    {
        $id = $this->createStudio('Pour l\'équipe', DeliverableScopeEnum::Shared);

        foreach ([[], ['scope' => ''], ['scope' => 'prive'], ['scope' => 5]] as $body) {
            $this->post(sprintf('/backend/studio/deliverables/%d/scope', $id), $body);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(DeliverableScopeEnum::Shared, $this->find($id)->getScope());
        }

        $this->post(sprintf('/backend/studio/deliverables/%d/scope', $id), ['scope' => 'personal']);
        self::assertResponseIsSuccessful();
        self::assertSame(DeliverableScopeEnum::Personal, $this->find($id)->getScope());
    }

    /** The second of two people saving the same shared document is told, not obeyed. */
    public function testASaveBasedOnAnOlderVersionIsRefusedUnlessForced(): void
    {
        $id = $this->createStudio('Écrit à deux', DeliverableScopeEnum::Shared);
        $opened = $this->editorPayload($id);

        // A colleague saves first: the date the first editor holds is now old.
        $this->entityManager->createQuery(sprintf('UPDATE %s d SET d.title = :title, d.updatedAt = :at WHERE d.id = :id', Deliverable::class))
            ->setParameter('title', 'Version de la collègue')
            ->setParameter('at', new DateTimeImmutable('+1 minute'))
            ->setParameter('id', $id)
            ->execute();

        $this->post(sprintf('/backend/studio/deliverables/%d/update', $id), [...$opened, 'title' => 'Mon titre']);
        self::assertResponseStatusCodeSame(409);
        self::assertTrue($this->json()['conflict']);
        self::assertSame('Version de la collègue', $this->find($id)->getTitle());

        $this->post(sprintf('/backend/studio/deliverables/%d/update', $id), [...$opened, 'title' => 'Mon titre', 'force' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('Mon titre', $this->find($id)->getTitle());
    }

    public function testASaveBasedOnTheCurrentVersionGoesThroughAndMovesTheDate(): void
    {
        $id = $this->createStudio('Écrit seul', DeliverableScopeEnum::Shared);

        $this->post(sprintf('/backend/studio/deliverables/%d/update', $id), [...$this->editorPayload($id), 'title' => 'Premier']);
        self::assertResponseIsSuccessful();
        $afterFirst = $this->json()['deliverable']['updatedAt'];

        // The editor sends back the date it was just given, and is not in conflict with itself.
        $this->post(sprintf('/backend/studio/deliverables/%d/update', $id), [...$this->editorPayload($id), 'title' => 'Second', 'updatedAt' => $afterFirst]);
        self::assertResponseIsSuccessful();
        self::assertSame('Second', $this->find($id)->getTitle());
    }

    public function testASaveThatCarriesNoDateIsNotCompared(): void
    {
        $id = $this->createStudio('Appel venu d\'ailleurs', DeliverableScopeEnum::Shared);

        $this->post(sprintf('/backend/studio/deliverables/%d/update', $id), [...$this->editorPayload($id), 'title' => 'Sans date']);

        self::assertResponseIsSuccessful();
    }

    public function testAnArchivedSpaceTakesNoNewDeliverableButKeepsItsOwn(): void
    {
        $space = $this->givenSpace();
        $id = $this->givenSpaceDeliverable($space, 'Déjà là');

        $this->entityManager->clear();
        $archived = $this->entityManager->find(CustomerSpace::class, $space->getId());
        $archived->setStatus(CustomerSpaceStatusEnum::Archived);
        $this->entityManager->flush();

        $this->post(sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => 'Nouveau']);
        self::assertResponseStatusCodeSame(403);

        $this->post(sprintf('/workspace/%d/deliverables/%d/duplicate', $space->getId(), $id), []);
        self::assertResponseStatusCodeSame(403);

        // What it already holds stays editable: archiving is not deleting.
        $this->post(sprintf('/workspace/%d/deliverables/%d/update', $space->getId(), $id), [...$this->editorPayload($id, $space), 'title' => 'Corrigé']);
        self::assertResponseIsSuccessful();
    }

    /** Neither « nobody », nor the previous author, nor the author, depending on the road taken. */
    public function testASpaceDeliverableBelongsToWhoCreatedOrDuplicatedIt(): void
    {
        $space = $this->givenSpace();
        $id = $this->givenSpaceDeliverable($space, 'Mon audit');
        self::assertSame($this->admin->getId(), $this->find($id)->getOwner()?->getId());

        $other = $this->accountWith(['studio.spaces.view', 'studio.spaces.edit']);
        $this->membership($space, $other);
        $this->client->loginUser($other, 'admin');
        $this->post(sprintf('/workspace/%d/deliverables/%d/duplicate', $space->getId(), $id), []);
        self::assertResponseIsSuccessful();

        $copies = array_filter($this->json()['deliverables'], static fn (array $row): bool => $row['id'] !== $id);
        self::assertCount(1, $copies);
        self::assertSame($other->getId(), $this->find((int) array_values($copies)[0]['id'])->getOwner()?->getId());
    }

    /** Opening a half-filled model to a client is exactly what the editor warns about; so does the list. */
    public function testOpeningADeliverableToTheClientNeedsConfirmationWhileBlanksRemain(): void
    {
        $space = $this->givenSpace();
        $id = $this->givenSpaceDeliverable($space, 'Audit [Nom du client]');

        $this->post(sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $id), ['visible' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('confirmation_needed', $this->json()['error']);
        self::assertSame(1, $this->json()['placeholders']);
        self::assertFalse($this->find($id)->isVisibleToClient());

        $this->post(sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $id), ['visible' => true, 'confirm' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->find($id)->isVisibleToClient());

        // Closing it never asks.
        $this->post(sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $id), ['visible' => false]);
        self::assertResponseIsSuccessful();
    }

    public function testAFinishedDeliverableOpensToTheClientWithoutAsking(): void
    {
        $space = $this->givenSpace();
        $id = $this->givenSpaceDeliverable($space, 'Audit prêt');

        $this->post(sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $id), ['visible' => true]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->find($id)->isVisibleToClient());
    }

    /** A personal deliverable of a colleague, or a space the reader is not on, never shows up as a title in the search. */
    public function testTheSearchFindsOnlyWhatTheReaderMayOpen(): void
    {
        $needle = 'Zibeline'.bin2hex(random_bytes(3));
        $this->createStudio('Mine '.$needle, DeliverableScopeEnum::Personal);
        $this->createStudio('Équipe '.$needle, DeliverableScopeEnum::Shared);
        $space = $this->givenSpace();
        $this->givenSpaceDeliverable($space, 'Espace '.$needle);

        $provider = self::getContainer()->get(StudioBackendSearchProvider::class);

        $this->client->loginUser($this->admin, 'admin');
        self::assertEqualsCanonicalizing(
            ['Mine '.$needle, 'Équipe '.$needle, 'Espace '.$needle],
            array_column($provider->search($needle)['deliverables'] ?? [], 'title'),
        );

        // A teammate who is on no space: the shared one only.
        $teammate = $this->accountWith([...self::TEAM, 'studio.spaces.view']);
        $this->client->loginUser($teammate, 'admin');
        self::assertSame(['Équipe '.$needle], array_column($provider->search($needle)['deliverables'] ?? [], 'title'));
    }

    public function testTheDashboardCountsTheStudioDeliverablesTheReaderSees(): void
    {
        $this->createStudio('À moi', DeliverableScopeEnum::Personal);
        $this->createStudio('À tous', DeliverableScopeEnum::Shared);

        $teammate = $this->accountWith(self::TEAM);
        $this->client->loginUser($teammate, 'admin');

        $count = self::getContainer()->get(DeliverableRepository::class)->countStandaloneFor($teammate);

        // The shared one only: the personal one belongs to somebody else.
        self::assertSame(1, $count);
    }

    /** A password typed with a trailing space opens the link it locked, and only failures count against the limit. */
    public function testTheUnlockLimitCountsFailuresPerLinkAndIgnoresSuccesses(): void
    {
        $id = $this->createStudio('Verrouillé', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), ['password' => '  phrase secrète  ']);
        self::assertResponseIsSuccessful();
        $token = $this->tokenOf($this->json()['links'][0]['url']);

        $this->client->loginUser($this->admin, 'admin');

        // More successes than the hourly limit: they do not count.
        for ($i = 0; $i < 25; ++$i) {
            $this->client->request('POST', sprintf('/deliverables/%s/unlock', $token), ['password' => 'phrase secrète ']);
            self::assertResponseRedirects(sprintf('/deliverables/%s', $token));
        }

        // Twenty failures, then the wall.
        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('POST', sprintf('/deliverables/%s/unlock', $token), ['password' => 'faux']);
            self::assertResponseIsSuccessful();
        }

        $this->client->request('POST', sprintf('/deliverables/%s/unlock', $token), ['password' => 'faux']);
        self::assertResponseStatusCodeSame(429);

        // Another link, from the same address, is not locked out with it.
        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), ['password' => self::OTHER_PHRASE]);
        $other = $this->tokenOf($this->json()['links'][0]['url']);
        $this->client->request('POST', sprintf('/deliverables/%s/unlock', $other), ['password' => self::OTHER_PHRASE]);
        self::assertResponseRedirects(sprintf('/deliverables/%s', $other));
    }

    public function testAnEditorPreviewWithAnUnknownLanguageFallsBackToTheDefault(): void
    {
        $id = $this->createStudio('Aperçu', DeliverableScopeEnum::Shared);
        $payload = $this->editorPayload($id);

        foreach ([true, false] as $frame) {
            $this->post('/backend/studio/deliverables/grid-preview', [
                'layout' => $payload['gridLayout'],
                'content' => $payload['gridContent'],
                'locale' => '../../etc/passwd',
                'frame' => $frame,
            ]);
            self::assertResponseIsSuccessful();
            self::assertNotSame('', $this->json()['html']);
        }
    }

    /**
     * A picture a deliverable draws, in a list's portraits or as its thumbnail,
     * is no longer announced « Inutilisé » and purged on schedule.
     */
    public function testTheLibraryKnowsWhichDeliverablesDrawAPicture(): void
    {
        $picture = $this->givenImage(DocumentStatusEnum::Published);
        $shared = $this->createStudio('Équipe', DeliverableScopeEnum::Shared);
        $personal = $this->createStudio('Secret', DeliverableScopeEnum::Personal);
        $this->setLayout($shared, [['id' => 'portraits', 'type' => 'items', 'items' => [['mediaId' => $picture]]]]);
        $this->entityManager->clear();
        $personalEntity = $this->entityManager->find(Deliverable::class, $personal);
        $personalEntity->setThumbnail($this->entityManager->find(Document::class, $picture));
        $this->entityManager->flush();

        $provider = self::getContainer()->get(DeliverableDocumentUsageProvider::class);

        self::assertSame([$picture => 2], $provider->countUsagesFor([$picture, 999999]));
        self::assertEqualsCanonicalizing(['Équipe', 'Secret'], array_column($provider->findUsages($picture), 'label'));
        self::assertSame([], $provider->findUsages(999999));

        // A teammate who may not open the personal one still sees that it is used,
        // and is not told what it is called.
        $this->client->loginUser($this->accountWith(self::TEAM), 'admin');
        $usages = $provider->findUsages($picture);
        self::assertCount(2, $usages);
        $labels = array_column($usages, 'label');
        self::assertContains('Équipe', $labels);
        self::assertNotContains('Secret', $labels);
        self::assertContains(null, array_column($usages, 'href'));
    }

    /** Pictures the reader will not see: every slot, and the thumbnail, and only the unpublished ones. */
    public function testTheReadinessReportNamesUnpublishedPicturesInEverySlot(): void
    {
        $draft = $this->givenImage(DocumentStatusEnum::Draft);
        $thumbnail = $this->givenImage(DocumentStatusEnum::Draft);
        $published = $this->givenImage(DocumentStatusEnum::Published);
        $id = $this->createStudio('Pas prêt', DeliverableScopeEnum::Shared);
        $this->setLayout($id, [
            ['id' => 'portraits', 'type' => 'items', 'items' => [['mediaId' => $draft], ['mediaId' => $published]]],
            ['id' => 'back', 'type' => 'text', 'background' => ['type' => 'solid', 'mediaId' => null, 'videoId' => $draft]],
        ]);
        $this->entityManager->clear();
        $this->entityManager->find(Deliverable::class, $id)->setThumbnail($this->entityManager->find(Document::class, $thumbnail));
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/backend/studio/deliverables/%d/links', $id));
        self::assertResponseIsSuccessful();

        $withheld = array_column($this->json()['withheldPictures'], 'id');
        self::assertEqualsCanonicalizing([$draft, $thumbnail], $withheld);
    }

    /** A picture in a zone the page does not show cannot hold the document back. */
    public function testAPictureInAHiddenZoneDoesNotHoldTheDocumentBack(): void
    {
        $draft = $this->givenImage(DocumentStatusEnum::Draft);
        $id = $this->createStudio('Zone cachée', DeliverableScopeEnum::Shared);
        $this->setLayout($id, [['id' => 'feed', 'type' => 'githubActivity', 'mediaId' => $draft]]);

        $this->client->request('GET', sprintf('/backend/studio/deliverables/%d/links', $id));

        self::assertSame([], $this->json()['withheldPictures']);
    }

    /** The blanks of the title, the summary and « Préparé pour » count too, not just the grid. */
    public function testBlanksOutsideTheGridCountAsWell(): void
    {
        $id = $this->createStudio('Audit [Client]', DeliverableScopeEnum::Shared);
        $this->post(sprintf('/backend/studio/deliverables/%d/update', $id), [
            ...$this->editorPayload($id),
            'summary' => 'Pour [Nom] en [mois]',
            'readingHeader' => ['preparedFor' => '[Client]'],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/backend/studio/deliverables/%d/links', $id));

        self::assertSame(4, $this->json()['placeholders']);
    }

    /** A hidden zone type already saved in a deliverable never reaches the client, nor holds back its reading time. */
    public function testAHiddenZoneAlreadySavedIsNotRenderedForTheClient(): void
    {
        $id = $this->createStudio('Avec un fil', DeliverableScopeEnum::Shared);
        $this->setLayout($id, [['id' => 'talk', 'type' => 'comments'], ['id' => 'words', 'type' => 'text']]);
        $this->post(sprintf('/backend/studio/deliverables/%d/links/create', $id), []);
        $token = $this->tokenOf($this->json()['links'][0]['url']);

        $this->client->request('GET', sprintf('/deliverables/%s', $token));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-grid-zone="talk"', (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->jsonRequest('POST', $uri, $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tokenOf(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    private function createStudio(string $title, DeliverableScopeEnum $scope): int
    {
        $this->post('/backend/studio/deliverables/create', ['title' => $title, 'scope' => $scope->value]);
        self::assertResponseIsSuccessful();

        foreach ($this->json()[$scope->value] as $row) {
            if ($row['title'] === $title) {
                return (int) $row['id'];
            }
        }

        self::fail(sprintf('Le livrable « %s » n\'est pas revenu dans son rayon.', $title));
    }

    private function givenSpaceDeliverable(CustomerSpace $space, string $title): int
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

    /** @return array<string, mixed> ce que l'éditeur enverrait, sans rien changer */
    private function editorPayload(int $id, ?CustomerSpace $space = null): array
    {
        $entity = $this->find($id);

        return [
            'title' => $entity->getTitle(),
            'summary' => $entity->getSummary(),
            'locale' => $entity->getLocale(),
            'gridLayout' => $entity->getGridLayout(),
            'gridContent' => $entity->getGridContent(),
            'appearance' => $entity->getAppearance(),
            'readingHeader' => $entity->getReadingHeader(),
            'visibleToClient' => $entity->isVisibleToClient(),
            'updatedAt' => $entity->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function givenImage(DocumentStatusEnum $status): int
    {
        $name = 'audit-'.bin2hex(random_bytes(4)).'.jpg';
        $document = new Document();
        $document->setTitle($name)->setFilePath('ged/2026/10/'.$name)->setFileName($name)->setOriginalName($name)->setMimeType('image/jpeg')->setSize(1024)->setStatus($status);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = (int) $document->getId();

        return (int) $document->getId();
    }

    /** @param list<array<string, mixed>> $zones */
    private function setLayout(int $id, array $zones): void
    {
        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        $deliverable->setGridLayout([...$deliverable->getGridLayout(), 'enabled' => true, 'zones' => $zones]);
        $this->entityManager->flush();
    }

    private function find(int $id): Deliverable
    {
        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $deliverable);

        return $deliverable;
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        $this->entityManager->clear();

        return array_map(
            static fn (AuditLog $log): string => $log->getAction(),
            $this->entityManager->getRepository(AuditLog::class)->findBy(['module' => 'studio']),
        );
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('audit-livrables-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Équipier '.bin2hex(random_bytes(2)))
            ->setType(UserTypeEnum::Backend)
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
        $customer->setLegalName('Client Audit')->setContractualEmail('audit-livrables@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin, 'admin');
        $this->post('/backend/studio/spaces/create', ['name' => 'Espace audit', 'customerId' => $customer->getId(), 'timezone' => 'Europe/Paris']);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function membership(CustomerSpace $space, User $user): void
    {
        $member = new CustomerSpaceMember();
        $member
            ->setSpace($this->entityManager->find(CustomerSpace::class, $space->getId()))
            ->setUser($this->entityManager->find(User::class, $user->getId()))
            ->setRole(CustomerSpaceMemberRoleEnum::Member);
        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }
}
