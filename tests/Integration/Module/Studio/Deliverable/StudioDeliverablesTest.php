<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategory;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function basename;
use function bin2hex;
use function json_decode;
use function parse_url;
use function random_bytes;
use function sprintf;

use const PHP_URL_PATH;

/**
 * Les livrables de Studio, ceux qui ne sont rattachés à aucun espace client :
 * perso ou partagés, et ce que chacun peut y faire.
 */
final class StudioDeliverablesTest extends IntegrationTestCase
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
        foreach ([DeliverableLink::class, Deliverable::class, DeliverableCategory::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        foreach ($this->documents as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s d WHERE d.id = :id', Document::class))->setParameter('id', $id)->execute();
        }

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        self::getContainer()->get(SettingRepository::class)->set(ModuleParameterEnum::StudioDeliverables->value, '1');
        self::getContainer()->get(ModuleAccessChecker::class)->reset();

        parent::tearDown();
    }

    /** Un livrable perso naît sans espace, à son auteur, et sans client à qui l'ouvrir. */
    public function testAStudioDeliverableIsBornWithoutAClient(): void
    {
        $id = $this->create('Proposition', DeliverableScopeEnum::Personal);

        $deliverable = $this->find($id);
        self::assertNull($deliverable->getSpace());
        self::assertSame($this->admin->getId(), $deliverable->getOwner()?->getId());
        self::assertSame(DeliverableScopeEnum::Personal, $deliverable->getScope());
        // Personne à nommer « préparé pour » : la normalisation n'en garde rien.
        self::assertEmpty($deliverable->getReadingHeader()['preparedFor'] ?? '');

        // La case « visible par le client » ne tient pas : il n'y a pas de client.
        $this->update($id, ['visibleToClient' => true]);
        self::assertFalse($this->find($id)->isVisibleToClient());

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d', $id));
        self::assertResponseIsSuccessful();
    }

    /** Ce que l'auteur garde pour lui, personne d'autre ne le voit ni ne l'ouvre. */
    public function testAPersonalDeliverableBelongsToItsAuthorAlone(): void
    {
        $id = $this->create('Brouillon', DeliverableScopeEnum::Personal);

        $teammate = $this->accountWith(self::TEAM);
        $this->client->loginUser($teammate, 'admin');

        self::assertNotContains($id, array_column($this->lists()['personal'], 'id'));
        self::assertNotContains($id, array_column($this->lists()['shared'], 'id'));

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d', $id));
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/update', $id), ['title' => 'Repris']);
        self::assertResponseStatusCodeSame(404);
    }

    /** Un livrable partagé se lit par l'équipe, et se modifie avec le droit. */
    public function testASharedDeliverableIsReadByTheTeamAndEditedWithTheRight(): void
    {
        $id = $this->create('Modèle d\'audit', DeliverableScopeEnum::Shared);

        $reader = $this->accountWith(['studio.deliverables.view']);
        $this->client->loginUser($reader, 'admin');
        self::assertContains($id, array_column($this->lists()['shared'], 'id'));

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d', $id));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/update', $id), $this->payload($id));
        self::assertResponseStatusCodeSame(403);

        $editor = $this->accountWith(['studio.deliverables.view', 'studio.deliverables.edit']);
        $this->client->loginUser($editor, 'admin');
        $this->update($id, ['title' => 'Modèle d\'audit, version 2']);
        self::assertSame('Modèle d\'audit, version 2', $this->find($id)->getTitle());
    }

    /** Passer de perso à partagé, ou l'inverse : à l'auteur seul, même pour qui peut modifier. */
    public function testOnlyTheAuthorMovesADeliverableBetweenScopes(): void
    {
        $author = $this->accountWith(self::TEAM);
        $this->client->loginUser($author, 'admin');
        $id = $this->create('Stratégie', DeliverableScopeEnum::Personal);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/scope', $id), ['scope' => 'shared']);
        self::assertResponseIsSuccessful();
        self::assertSame(DeliverableScopeEnum::Shared, $this->find($id)->getScope());

        $other = $this->accountWith(self::TEAM);
        $this->client->loginUser($other, 'admin');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/scope', $id), ['scope' => 'personal']);
        self::assertResponseStatusCodeSame(403);

        // Envoyé avec un enregistrement, le rayon est ignoré pour qui n'est pas l'auteur.
        $this->update($id, ['scope' => 'personal']);
        self::assertSame(DeliverableScopeEnum::Shared, $this->find($id)->getScope());
    }

    /**
     * L'auteur garde la main sur ce qu'il a écrit : il modifie le livrable
     * qu'il a partagé, et jette son brouillon perso, sans les droits de
     * modification ni de suppression du module.
     */
    public function testTheAuthorKeepsTheirOwnDeliverablesWithoutTheModuleRights(): void
    {
        $author = $this->accountWith(['studio.deliverables.view', 'studio.deliverables.create']);
        $this->client->loginUser($author, 'admin');

        $shared = $this->create('Partagé sans droit', DeliverableScopeEnum::Shared);
        $this->update($shared, ['title' => 'Partagé, repris par son auteur']);
        self::assertSame('Partagé, repris par son auteur', $this->find($shared)->getTitle());

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/delete', $shared));
        self::assertResponseStatusCodeSame(403);

        $personal = $this->create('Brouillon à jeter', DeliverableScopeEnum::Personal);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/delete', $personal));
        self::assertResponseIsSuccessful();

        // Put in the trash, not destroyed: the author's own draft is theirs to bin
        // without a right, and to take back.
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(Deliverable::class, $personal)?->isTrashed());
    }

    public function testCreatingNeedsItsOwnRight(): void
    {
        $this->client->loginUser($this->accountWith(['studio.deliverables.view']), 'admin');

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Interdit', 'scope' => 'personal']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheModuleNeedsItsViewRight(): void
    {
        $this->client->loginUser($this->accountWith(['studio.spaces.view']), 'admin');

        $this->client->request('GET', '/suite/studio/deliverables');
        self::assertResponseStatusCodeSame(403);
    }

    /** Un livrable perso dont l'auteur a disparu revient à l'administrateur. */
    public function testAnOrphanedPersonalDeliverableGoesToTheAdministrators(): void
    {
        $author = $this->accountWith(self::TEAM);
        $this->client->loginUser($author, 'admin');
        $id = $this->create('Laissé là', DeliverableScopeEnum::Personal);

        $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $author->getId())->execute();
        $this->entityManager->clear();
        self::assertNull($this->find($id)->getOwner());

        $admin = self::getContainer()->get(UserRepository::class)->find($this->admin->getId());
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
        self::assertContains($id, array_column($this->lists()['personal'], 'id'));

        // Le partager le rattache à qui l'a recueilli.
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/scope', $id), ['scope' => 'shared']);
        self::assertResponseIsSuccessful();
        self::assertSame($this->admin->getId(), $this->find($id)->getOwner()?->getId());
    }

    /** Ses liens de lecture marchent comme ceux d'un espace, et s'éteignent avec le module. */
    public function testItsReadingLinkWorksAndDiesWithTheModule(): void
    {
        $id = $this->create('À envoyer', DeliverableScopeEnum::Shared);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/links/create', $id), ['label' => 'Pour Marie']);
        self::assertResponseIsSuccessful();
        $links = json_decode((string) $this->client->getResponse()->getContent(), true)['links'];
        self::assertCount(1, $links);
        $path = (string) parse_url($links[0]['url'], PHP_URL_PATH);

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        self::getContainer()->get(SettingRepository::class)->set(ModuleParameterEnum::StudioDeliverables->value, '0');
        self::getContainer()->get(ModuleAccessChecker::class)->reset();

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/suite/studio/deliverables');
        self::assertResponseStatusCodeSame(404);
    }

    /** Un livrable d'espace s'ouvre par son espace, jamais par Studio ; et l'inverse. */
    public function testSpaceAndStudioDeliverablesKeepToTheirOwnAddresses(): void
    {
        $standalone = $this->create('Hors espace', DeliverableScopeEnum::Shared);
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => "Dans l'espace"]);
        self::assertResponseIsSuccessful();
        $rows = json_decode((string) $this->client->getResponse()->getContent(), true)['deliverables'];
        self::assertSame(["Dans l'espace"], array_column($rows, 'title'), 'a Studio deliverable never shows in a space');
        $inSpace = (int) $rows[0]['id'];

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d', $inSpace));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d', $space->getId(), $standalone));
        self::assertResponseStatusCodeSame(404);
    }

    /** Une copie est à qui la fait, dans le rayon de l'original. */
    public function testACopyBelongsToWhoeverMadeIt(): void
    {
        $id = $this->create('Gabarit', DeliverableScopeEnum::Shared);

        $teammate = $this->accountWith(self::TEAM);
        $this->client->loginUser($teammate, 'admin');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/duplicate', $id), []);
        self::assertResponseIsSuccessful();
        $copyId = (int) basename((string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath']);

        $copy = $this->find($copyId);
        self::assertSame($teammate->getId(), $copy->getOwner()?->getId());
        self::assertSame(DeliverableScopeEnum::Shared, $copy->getScope());
        self::assertNull($copy->getSpace());
    }

    /** Le modèle de Studio recopié chez un client : la copie vit dans l'espace, l'original reste. */
    public function testACopyLandsInAClientSpaceAndTheOriginalStays(): void
    {
        $id = $this->create('Modèle d\'audit', DeliverableScopeEnum::Personal);
        $this->update($id, ['summary' => 'À remplir pour chaque client']);
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => $space->getId(), 'title' => 'Audit du client']);
        self::assertResponseIsSuccessful();
        $editPath = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath'];
        self::assertStringStartsWith(sprintf('/workspace/%d/deliverables/', $space->getId()), (string) parse_url($editPath, PHP_URL_PATH));

        $copy = $this->find((int) basename($editPath));
        self::assertSame($space->getId(), $copy->getSpace()?->getId());
        self::assertSame('Audit du client', $copy->getTitle());
        self::assertSame('À remplir pour chaque client', $copy->getSummary());
        self::assertFalse($copy->isVisibleToClient(), 'a copy is work in progress');
        self::assertSame('Client livrables', $copy->getReadingHeader()['preparedFor'] ?? null);

        $original = $this->find($id);
        self::assertNull($original->getSpace());
        self::assertSame('Modèle d\'audit', $original->getTitle());

        $this->client->request('GET', $editPath);
        self::assertResponseIsSuccessful();
    }

    /** Un espace qu'on ne voit pas, ou qui n'existe pas, ne reçoit rien ; sans titre, la copie garde celui de l'original. */
    public function testCopyingIntoASpaceNeedsToSeeIt(): void
    {
        $id = $this->create('Gabarit partagé', DeliverableScopeEnum::Shared);
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => 999999]);
        self::assertResponseStatusCodeSame(404);

        $teammate = $this->accountWith([...self::TEAM, 'studio.spaces.view', 'studio.spaces.edit']);
        $this->client->loginUser($teammate, 'admin');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => $space->getId()]);
        self::assertResponseStatusCodeSame(404, 'a space the teammate is not a member of stays unknown to them');

        $this->client->loginUser($this->admin, 'admin');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => (string) $space->getId()]);
        self::assertResponseIsSuccessful();
        $copyId = (int) basename((string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath']);
        self::assertSame('Gabarit partagé', $this->find($copyId)->getTitle());
    }

    /** Un livrable d'espace gardé comme modèle : la copie arrive dans « Mes livrables », sans client. */
    public function testASpaceDeliverableCopiesIntoStudio(): void
    {
        $space = $this->givenSpace();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => 'Stratégie du client']);
        self::assertResponseIsSuccessful();
        $inSpace = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['deliverables'][0]['id'];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/copy-to-studio', $space->getId(), $inSpace), []);
        self::assertResponseIsSuccessful();
        $editPath = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath'];
        self::assertStringStartsWith('/suite/studio/deliverables/', (string) parse_url($editPath, PHP_URL_PATH));

        $copy = $this->find((int) basename($editPath));
        self::assertNull($copy->getSpace());
        self::assertSame($this->admin->getId(), $copy->getOwner()?->getId());
        self::assertSame(DeliverableScopeEnum::Personal, $copy->getScope());
        self::assertSame('Stratégie du client', $copy->getTitle());
        self::assertEmpty($copy->getReadingHeader()['preparedFor'] ?? '');
        self::assertContains($copy->getId(), array_column($this->lists()['personal'], 'id'));

        self::assertSame($space->getId(), $this->find($inSpace)->getSpace()?->getId(), 'the space keeps its own');
    }

    /** Module des livrables éteint : rien ne part d'un espace vers Studio. */
    public function testCopyingIntoStudioNeedsTheModule(): void
    {
        $space = $this->givenSpace();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => 'Rapport']);
        $inSpace = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['deliverables'][0]['id'];

        self::getContainer()->get(SettingRepository::class)->set(ModuleParameterEnum::StudioDeliverables->value, '0');
        self::getContainer()->get(ModuleAccessChecker::class)->reset();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/copy-to-studio', $space->getId(), $inSpace), []);
        self::assertResponseStatusCodeSame(403);
    }

    /** Une catégorie se crée, se renomme, se range ; ses livrables la portent sur leur carte. */
    public function testADeliverableIsFiledUnderACategory(): void
    {
        $audit = $this->createCategory('Audit', '#bd4a55');
        $strategy = $this->createCategory('Stratégie', null);

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Modèle d\'audit', 'scope' => 'personal', 'categoryId' => $audit]);
        self::assertResponseIsSuccessful();
        $id = (int) basename((string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath']);
        self::assertSame($audit, $this->find($id)->getCategory()?->getId());

        $this->update($id, ['categoryId' => $strategy]);
        self::assertSame($strategy, $this->find($id)->getCategory()?->getId());

        $row = $this->rowOf($id);
        self::assertSame(['id' => $strategy, 'name' => 'Stratégie', 'color' => null, 'position' => 2], $row['category']);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/categories/%d/update', $strategy), ['name' => 'Stratégie éditoriale', 'color' => '#2a2050']);
        self::assertResponseIsSuccessful();
        self::assertSame('Stratégie éditoriale', $this->rowOf($id)['category']['name']);

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/categories/reorder', ['ids' => [$strategy, $audit]]);
        self::assertResponseIsSuccessful();
        $categories = json_decode((string) $this->client->getResponse()->getContent(), true)['categories'];
        self::assertSame([$strategy, $audit], array_column($categories, 'id'));

        // Un identifiant que rien ne résout laisse sans catégorie, sans refuser l'enregistrement.
        $this->update($id, ['categoryId' => 999999]);
        self::assertNull($this->find($id)->getCategory());
    }

    /** Supprimer une catégorie laisse ses livrables, sans catégorie. */
    public function testDeletingACategoryKeepsItsDeliverables(): void
    {
        $audit = $this->createCategory('Audit', null);
        $id = $this->create('Modèle', DeliverableScopeEnum::Shared);
        $this->update($id, ['categoryId' => $audit]);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/categories/%d/delete', $audit), []);
        self::assertResponseIsSuccessful();

        self::assertNull($this->find($id)->getCategory());
        self::assertNull($this->rowOf($id)['category']);
    }

    /** Une catégorie a un nom, et une couleur valide quand elle en a une. */
    public function testACategoryNeedsANameAndAValidColour(): void
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/categories/create', ['name' => '  ']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/categories/create', ['name' => 'Audit', 'color' => 'red']);
        self::assertResponseStatusCodeSame(422);
    }

    /** Ranger la bibliothèque demande le droit de modifier les livrables ; ranger son livrable, non. */
    public function testManagingCategoriesNeedsTheEditRight(): void
    {
        $audit = $this->createCategory('Audit', null);

        $author = $this->accountWith(['studio.deliverables.view', 'studio.deliverables.create']);
        $this->client->loginUser($author, 'admin');

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/categories/create', ['name' => 'Proposition']);
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/categories/%d/delete', $audit), []);
        self::assertResponseStatusCodeSame(403);

        $id = $this->create('Mon brouillon', DeliverableScopeEnum::Personal);
        $this->update($id, ['categoryId' => $audit]);
        self::assertSame($audit, $this->find($id)->getCategory()?->getId());
    }

    /** La copie dans Studio garde la catégorie ; la copie chez un client la laisse, l'espace range seul. */
    public function testACopyKeepsItsCategoryOnlyInStudio(): void
    {
        $audit = $this->createCategory('Audit', null);
        $id = $this->create('Modèle d\'audit', DeliverableScopeEnum::Shared);
        $this->update($id, ['categoryId' => $audit]);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/duplicate', $id), []);
        $copy = (int) basename((string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath']);
        self::assertSame($audit, $this->find($copy)->getCategory()?->getId());

        $space = $this->givenSpace();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => $space->getId()]);
        $inSpace = (int) basename((string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath']);
        self::assertNull($this->find($inSpace)->getCategory());
    }

    /** Une image de la médiathèque devient la vignette de la carte ; un PDF, non ; les copies la gardent. */
    public function testADeliverableCarriesAnImage(): void
    {
        $image = $this->givenDocument('Couverture', 'image/jpeg');
        $pdf = $this->givenDocument('Contrat', 'application/pdf');
        $id = $this->create('Modèle illustré', DeliverableScopeEnum::Personal);

        $this->update($id, ['thumbnailId' => $image]);
        self::assertSame($image, $this->find($id)->getThumbnail()?->getId());
        self::assertNotNull($this->rowOf($id)['thumbnailUrl']);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/duplicate', $id), []);
        $copy = (int) basename((string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath']);
        self::assertSame($image, $this->find($copy)->getThumbnail()?->getId());

        $space = $this->givenSpace();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => $space->getId()]);
        $inSpace = (int) basename((string) json_decode((string) $this->client->getResponse()->getContent(), true)['editPath']);
        self::assertSame($image, $this->find($inSpace)->getThumbnail()?->getId());

        $this->update($id, ['thumbnailId' => $pdf]);
        self::assertNull($this->find($id)->getThumbnail(), 'a PDF is no thumbnail');
        self::assertNull($this->rowOf($id)['thumbnailUrl']);
    }

    private function givenDocument(string $title, string $mime): int
    {
        $extension = 'image/jpeg' === $mime ? 'jpg' : 'pdf';
        $document = new Document();
        $document->setTitle($title)->setFilePath('ged/2026/10/'.bin2hex(random_bytes(4)).'.'.$extension)->setFileName('f.'.$extension)->setOriginalName('f.'.$extension)->setMimeType($mime)->setSize(1024);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = (int) $document->getId();

        return (int) $document->getId();
    }

    private function createCategory(string $name, ?string $color): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/categories/create', ['name' => $name, 'color' => $color]);
        self::assertResponseIsSuccessful();

        return (int) json_decode((string) $this->client->getResponse()->getContent(), true)['categoryId'];
    }

    /** @return array<string, mixed> */
    private function rowOf(int $id): array
    {
        $lists = $this->lists();
        foreach ([...$lists['personal'], ...$lists['shared']] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        self::fail(sprintf("Le livrable %d n'est dans aucun rayon.", $id));
    }

    private function create(string $title, DeliverableScopeEnum $scope): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => $title, 'scope' => $scope->value]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        foreach ($data[$scope->value] as $row) {
            if ($row['title'] === $title) {
                return (int) $row['id'];
            }
        }

        self::fail(sprintf('Le livrable « %s » n\'est pas revenu dans son rayon.', $title));
    }

    /** @return array{personal: list<array<string, mixed>>, shared: list<array<string, mixed>>} */
    private function lists(): array
    {
        $this->client->request('GET', '/suite/studio/deliverables/lists');
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $changes */
    private function update(int $id, array $changes): void
    {
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/update', $id), [...$this->payload($id), ...$changes]);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function payload(int $id): array
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
            'categoryId' => $entity->getCategory()?->getId(),
            'thumbnailId' => $entity->getThumbnail()?->getId(),
        ];
    }

    private function find(int $id): Deliverable
    {
        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $deliverable);

        return $deliverable;
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('livrables-'.bin2hex(random_bytes(5)).'@aurora.app')
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
        $customer->setLegalName('Client livrables')->setContractualEmail('livrables@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace livrables',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
