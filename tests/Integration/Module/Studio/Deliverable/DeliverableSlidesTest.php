<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
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
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Service\DeliverableDocumentUsageProvider;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_column;
use function basename;
use function bin2hex;
use function copy;
use function dirname;
use function json_decode;
use function mb_substr;
use function parse_url;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;

use const PHP_URL_PATH;

/**
 * Un livrable au format diaporama : composé avec l'éditeur des présentations,
 * présenté, imprimé et lu par un lien, sous la règle d'accès des livrables.
 *
 * Ce qui se casserait sans bruit : une diapositive d'un autre livrable écrite
 * par l'adresse de celui-ci, les notes de l'orateur sur la page d'un lecteur,
 * une copie qui perdrait ses diapositives ou son thème, un diaporama déposé
 * dans un espace qui ne sait pas le lire, ou une image de diapositive que la
 * médiathèque croirait inutilisée.
 */
final class DeliverableSlidesTest extends IntegrationTestCase
{
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
    }

    protected function tearDown(): void
    {
        foreach ([DeliverableLink::class, Deliverable::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType = 'Deliverable'", AuditLog::class))->execute();

        foreach ($this->documents as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s d WHERE d.id = :id', Document::class))->setParameter('id', $id)->execute();
        }

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    /** Un diaporama s'ouvre dans l'éditeur des présentations ; une page, dans celui des pages. */
    public function testASlidesDeliverableOpensTheSlideEditor(): void
    {
        $id = $this->createSlides('Comité de pilotage');

        $this->client->request('GET', '/suite/studio/deliverables/'.$id);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('DeliverableSlidesEditorApp', $body);
        self::assertStringNotContainsString('DeliverableEditorApp&quot;', $body);

        $page = $this->createPage('Bilan');
        $this->client->request('GET', '/suite/studio/deliverables/'.$page);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('DeliverableSlidesEditorApp', (string) $this->client->getResponse()->getContent());
    }

    /** Ajouter, écrire, dupliquer, ranger et retirer une diapositive, par les routes de l'éditeur. */
    public function testSlidesAreWrittenThroughTheDeliverable(): void
    {
        $id = $this->createSlides('Lancement');

        $first = $this->addSlide($id, 'title');
        $second = $this->addSlide($id, 'bullets');

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $id, $first), [
            'content' => ['title' => 'Réunion de lancement', 'subtitle' => 'Octobre', 'notASlot' => 'ignoré'],
            'speakerNotes' => 'Remercier pour le temps pris.',
        ]);
        self::assertResponseIsSuccessful();
        $slide = $this->json()['slide'];
        self::assertSame('Réunion de lancement', $slide['content']['title']);
        self::assertArrayNotHasKey('notASlot', $slide['content'], 'the layout decides what a slide holds');
        self::assertSame('Remercier pour le temps pris.', $slide['speakerNotes']);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/duplicate', $id, $first));
        self::assertResponseIsSuccessful();
        $copy = (int) $this->json()['slide']['id'];
        self::assertSame(1, $this->json()['slide']['position'], 'the copy lands right after its source');

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/reorder', $id), ['orderedIds' => [$second, $first, $copy]]);
        self::assertResponseIsSuccessful();
        self::assertSame([$second, $first, $copy], array_column($this->json()['deck']['slides'], 'id'));

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/delete', $id, $copy));
        self::assertResponseIsSuccessful();

        $deliverable = $this->find($id);
        self::assertCount(2, $deliverable->getSlides());
        self::assertSame('Réunion de lancement', $deliverable->getSlides()->last()->getContent()['title']);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $id), ['layout' => 'poster']);
        self::assertResponseStatusCodeSame(422);
    }

    /** L'apparence des diapositives s'écrit, revient résolue, et refuse un thème inconnu. */
    public function testTheAppearanceIsWrittenAndComesBackResolved(): void
    {
        $id = $this->createSlides('Thème');

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/appearance', $id), [
            'theme' => 'ink',
            'style' => ['accent' => '#ff6600', 'slideNumbers' => true, 'nonsense' => 'dropped'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('ink', $this->json()['theme']);
        self::assertSame('#ff6600', $this->json()['appearance']['accent']);
        self::assertArrayNotHasKey('nonsense', $this->json()['style']);

        $deliverable = $this->find($id);
        self::assertSame(DeckThemeEnum::Ink, $deliverable->getSlideTheme());
        self::assertTrue($deliverable->getSlideStyle()['slideNumbers']);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/appearance', $id), ['theme' => 'neon']);
        self::assertResponseStatusCodeSame(422);
    }

    /** Une diapositive d'un autre livrable ne s'écrit pas par celui-ci, et une page n'a pas de diapositives. */
    public function testASlideIsOnlyWrittenThroughItsOwnDeliverable(): void
    {
        $mine = $this->createSlides('Le mien');
        $other = $this->createSlides('Un autre');
        $foreign = $this->addSlide($other, 'title');

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $mine, $foreign), ['content' => ['title' => 'Piraté']]);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/duplicate', $mine, $foreign));
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/delete', $mine, $foreign));
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->find($other)->getSlides());

        $page = $this->createPage('Une page');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $page), ['layout' => 'title']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/presenter', $page));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * La règle des livrables : un diaporama perso d'un autre ne s'ouvre pas
     * (404), un diaporama partagé se lit sans le droit de modifier mais ne
     * s'écrit pas (403).
     */
    public function testTheDeliverablesAccessRuleApplies(): void
    {
        $personal = $this->createSlides('Perso', 'personal');
        $shared = $this->createSlides('Partagé', 'shared');
        $this->addSlide($shared, 'title');

        $reader = $this->accountWith(['studio.deliverables.view']);
        $this->client->loginUser($reader, 'admin');

        $this->client->request('GET', '/suite/studio/deliverables/'.$personal);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $personal), ['layout' => 'title']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/presenter', $shared));
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $shared), ['layout' => 'title']);
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/appearance', $shared), ['theme' => 'ink']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->find($shared)->getSlides());
    }

    /** La vue présentateur porte les notes, et demande un compte. */
    public function testThePresenterPageCarriesTheNotesAndNeedsAnAccount(): void
    {
        $id = $this->createSlides('Présentateur');
        $slide = $this->addSlide($id, 'section');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $id, $slide), [
            'content' => ['title' => 'Deuxième partie'],
            'speakerNotes' => 'Marquer un temps avant la troisième puce.',
        ]);

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/presenter', $id));
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('DeckPresenterApp', $body);
        self::assertStringContainsString('Marquer un temps', $body);
        self::assertStringContainsString('deliverable-'.$id, $body, 'its own channel, never a deck of the same id');

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/print', $id));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DeckPrintApp', (string) $this->client->getResponse()->getContent());

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/presenter', $id));
        self::assertResponseRedirects();
    }

    /**
     * Le lien de lecture d'un diaporama montre ses diapositives, sans les notes
     * de l'orateur, et garde les règles des liens : un mot de passe demandé
     * sans rien nommer, un lien retiré qui ne répond plus.
     */
    public function testTheReadingLinkShowsTheSlidesWithoutTheNotes(): void
    {
        $id = $this->createSlides('Lu par le client');
        $slide = $this->addSlide($id, 'title');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $id, $slide), [
            'content' => ['title' => 'Bilan du trimestre'],
            'speakerNotes' => 'Ne pas citer le chiffre de mars.',
        ]);

        $token = $this->readingLink($id, 'premier');
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/deliverables/'.$token);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('PublicDeckApp', $body);
        self::assertStringContainsString('Bilan du trimestre', $body);
        self::assertStringNotContainsString('Ne pas citer', $body);
        self::assertStringNotContainsString('speakerNotes', $body);
        self::assertSame(1, $this->entityManager->getRepository(DeliverableLink::class)->findOneBy(['label' => 'premier'])?->getOpenCount());

        $this->client->loginUser($this->admin, 'admin');
        $locked = $this->readingLink($id, 'protégé', ['password' => 'phrase secrète']);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/deliverables/'.$locked);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Bilan du trimestre', (string) $this->client->getResponse()->getContent());

        // L'aperçu de l'auteur, comme le lira le destinataire : sans notes non plus.
        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/preview', $id));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('PublicDeckApp', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Ne pas citer', (string) $this->client->getResponse()->getContent());

        $link = $this->entityManager->getRepository(DeliverableLink::class)->findOneBy(['label' => 'premier']);
        self::assertInstanceOf(DeliverableLink::class, $link);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/links/%d/revoke', $id, $link->getId()));
        self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/deliverables/'.$token);
        self::assertResponseStatusCodeSame(404);
    }

    /** Dupliquer, ou partir d'un modèle, emporte les diapositives, leurs notes et leur thème. */
    public function testCopiesKeepTheSlidesAndTheirLook(): void
    {
        $id = $this->createSlides('Trame de lancement');
        $slide = $this->addSlide($id, 'title');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $id, $slide), [
            'content' => ['title' => 'Bienvenue'],
            'speakerNotes' => 'Se présenter.',
        ]);
        $this->addSlide($id, 'end');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/appearance', $id), ['theme' => 'paper', 'style' => ['footerText' => 'Studio']]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/duplicate', $id));
        self::assertResponseIsSuccessful();
        $copy = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertSame(DeliverableFormatEnum::Slides, $copy->getFormat());
        self::assertCount(2, $copy->getSlides());
        self::assertSame('Bienvenue', $copy->getSlides()->first()->getContent()['title']);
        self::assertSame('Se présenter.', $copy->getSlides()->first()->getSpeakerNotes());
        self::assertSame(DeckThemeEnum::Paper, $copy->getSlideTheme());
        self::assertSame('Studio', $copy->getSlideStyle()['footerText']);

        $this->update($id, ['template' => true]);
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Lancement Fabre', 'format' => 'slides', 'fromTemplateId' => $id]);
        self::assertResponseIsSuccessful();
        $fromTemplate = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertCount(2, $fromTemplate->getSlides());
        self::assertFalse($fromTemplate->isTemplate());
        self::assertSame(DeckThemeEnum::Paper, $fromTemplate->getSlideTheme());

        // Un modèle de l'autre format ne compte pas : une page vide, pas un diaporama.
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Une page', 'format' => 'page', 'fromTemplateId' => $id]);
        self::assertResponseIsSuccessful();
        $page = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertSame(DeliverableFormatEnum::Page, $page->getFormat());
        self::assertCount(0, $page->getSlides());
    }

    /** Un diaporama reste dans Studio : la copie vers un espace le refuse. */
    public function testASlidesDeliverableIsNotCopiedIntoASpace(): void
    {
        $id = $this->createSlides('Pas pour un espace');
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => $space->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('suite.studio.deliverables.errors.slides_not_in_space', $this->json()['errors']['format'] ?? null);
        self::assertSame(0, (int) $this->entityManager->createQuery(sprintf('SELECT COUNT(d.id) FROM %s d WHERE d.space IS NOT NULL', Deliverable::class))->getSingleScalarResult());

        $this->client->request('GET', '/suite/studio/deliverables/'.$id);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('copy-to-space', (string) $this->client->getResponse()->getContent(), 'the slides editor offers no copy into a space');
    }

    /**
     * Une image posée sur une diapositive, ou le logo des diapositives, est
     * comptée par la médiathèque, et nommée au moment de donner un lien quand
     * elle n'est pas publiée.
     */
    public function testThePicturesOfTheSlidesAreCountedAndWarnedAbout(): void
    {
        $picture = $this->picture(DocumentStatusEnum::Draft, 'facade.jpg');
        $logo = $this->picture(DocumentStatusEnum::Published, 'logo.png');
        $unused = $this->picture(DocumentStatusEnum::Published, 'rien.jpg');

        $id = $this->createSlides('Avec images');
        $slide = $this->addSlide($id, 'image');
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $id, $slide), ['content' => ['mediaId' => $picture]]);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/appearance', $id), ['theme' => 'slate', 'style' => ['logoMediaId' => $logo]]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $counts = self::getContainer()->get(DeliverableDocumentUsageProvider::class)->countUsagesFor([$picture, $logo, $unused]);
        self::assertSame(1, $counts[$picture] ?? 0);
        self::assertSame(1, $counts[$logo] ?? 0);
        self::assertSame(0, $counts[$unused] ?? 0);

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/links', $id));
        self::assertResponseIsSuccessful();
        self::assertSame(['facade.jpg'], array_column($this->json()['withheldPictures'], 'name'));
    }

    /**
     * Une police déposée depuis un diaporama se sert sous les livrables, sans
     * compte, et suit leur interrupteur : couper les présentations n'éteint
     * pas les polices d'un lien de lecture, couper les livrables, si.
     */
    public function testAnUploadedFontIsServedUnderTheDeliverables(): void
    {
        $id = $this->createSlides('Avec une police');

        $path = (string) tempnam(sys_get_temp_dir(), 'font');
        copy(dirname(__DIR__, 5).'/node_modules/@fontsource/lobster/files/lobster-latin-400-normal.woff2', $path);
        $this->client->request('POST', sprintf('/suite/studio/deliverables/%d/fonts/upload', $id), [], [
            'file' => new UploadedFile($path, 'Marque Display.woff2', null, null, true),
        ]);
        self::assertResponseIsSuccessful();
        $font = $this->json()['font'];
        $this->documents[] = (int) mb_substr((string) $font['key'], 7);
        self::assertStringStartsWith('/deliverables/fonts/', (string) $font['url']);

        $settings = self::getContainer()->get(SettingRepository::class);
        $checker = self::getContainer()->get(ModuleAccessChecker::class);
        $this->client->getCookieJar()->clear();

        try {
            $settings->set(ModuleParameterEnum::StudioDecks->value, '0');
            $checker->reset();
            $this->client->request('GET', (string) $font['url']);
            self::assertResponseIsSuccessful();
            self::assertSame('font/woff2', $this->client->getResponse()->headers->get('Content-Type'));

            $settings->set(ModuleParameterEnum::StudioDeliverables->value, '0');
            $checker->reset();
            $this->client->request('GET', (string) $font['url']);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $settings->set(ModuleParameterEnum::StudioDecks->value, '1');
            $settings->set(ModuleParameterEnum::StudioDeliverables->value, '1');
            $checker->reset();
        }
    }

    private function createSlides(string $title, string $scope = 'personal'): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => $title, 'scope' => $scope, 'format' => 'slides']);
        self::assertResponseIsSuccessful();

        return (int) basename((string) $this->json()['editPath']);
    }

    private function createPage(string $title): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => $title, 'scope' => 'personal']);
        self::assertResponseIsSuccessful();

        return (int) basename((string) $this->json()['editPath']);
    }

    private function addSlide(int $id, string $layout): int
    {
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $id), ['layout' => $layout]);
        self::assertResponseIsSuccessful();

        return (int) $this->json()['slide']['id'];
    }

    /** @param array<string, mixed> $changes */
    private function update(int $id, array $changes): void
    {
        $deliverable = $this->find($id);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/update', $id), [
            'title' => $deliverable->getTitle(),
            'locale' => $deliverable->getLocale(),
            'scope' => $deliverable->getScope()->value,
            ...$changes,
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Un lien de lecture neuf, sous ce libellé, et son jeton.
     *
     * @param array<string, mixed> $settings
     */
    private function readingLink(int $id, string $label, array $settings = []): string
    {
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/links/create', $id), ['label' => $label, ...$settings]);
        self::assertResponseIsSuccessful();

        foreach ($this->json()['links'] as $link) {
            if ($label === $link['label']) {
                return basename((string) parse_url((string) $link['url'], PHP_URL_PATH));
            }
        }

        self::fail('the link just created is not listed');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
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
            ->setEmail('diapos-'.bin2hex(random_bytes(5)).'@aurora.app')
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

    private function picture(DocumentStatusEnum $status, string $name): int
    {
        $document = new Document();
        $document
            ->setTitle($name)
            ->setOriginalName($name)
            ->setFilePath('ged/2026/10/'.bin2hex(random_bytes(4)).'-'.$name)
            ->setMimeType('image/jpeg')
            ->setStatus($status);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = (int) $document->getId();

        return (int) $document->getId();
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client diaporama')->setContractualEmail(bin2hex(random_bytes(4)).'@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace diaporama '.(new DateTimeImmutable())->format('His'),
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
