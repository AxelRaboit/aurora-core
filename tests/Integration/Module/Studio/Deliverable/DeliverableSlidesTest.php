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
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Service\DeliverableDocumentUsageProvider;
use Aurora\Module\Studio\Deliverable\Slides\Enum\DeckThemeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
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
 * A deliverable in the slides format: composed with the presentation editor,
 * presented, printed and read through a link, under the deliverables access
 * rule.
 *
 * What would break silently: a slide of another deliverable written through
 * this one's address, the speaker notes on a reader's page, a copy that would
 * lose its slides or its theme, or a slide image the media library would
 * believe unused. Presentations kept in a client space are covered by
 * SpaceSlidesTest.
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

    /** A slideshow opens in the presentation editor; a page, in the page editor. */
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

    /** Adding, writing, duplicating, reordering and removing a slide, through the editor's routes. */
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

    /** The slides' appearance is written, comes back resolved, and refuses an unknown theme. */
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

    /** A slide of another deliverable cannot be written through this one, and a page has no slides. */
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
     * The deliverables rule: someone else's personal slideshow does not open
     * (404), a shared slideshow can be read without the right to edit but
     * cannot be written (403).
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

    /** The presenter view carries the notes, and requires an account. */
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
     * A slideshow's reading link shows its slides, without the speaker notes,
     * and keeps the link rules: a password asked for without naming anything,
     * a withdrawn link that no longer answers.
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

        // The author's preview, as the recipient will read it: without notes either.
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

    /** Duplicating, or starting from a template, takes the slides, their notes and their theme. */
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

        // A template of the other format does not count: an empty page, not a slideshow.
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Une page', 'format' => 'page', 'fromTemplateId' => $id]);
        self::assertResponseIsSuccessful();
        $page = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertSame(DeliverableFormatEnum::Page, $page->getFormat());
        self::assertCount(0, $page->getSlides());
    }

    /**
     * An image placed on a slide, or the slides' logo, is counted by the media
     * library, and named when a link is given if it is not published.
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
     * A font uploaded from a slideshow is served under the deliverables,
     * without an account. It goes dark only when neither Studio deliverables
     * nor client spaces, which both hold presentations, are on.
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
            $this->client->request('GET', (string) $font['url']);
            self::assertResponseIsSuccessful();
            self::assertSame('font/woff2', $this->client->getResponse()->headers->get('Content-Type'));

            // A space presentation still needs it with the Deliverables module off.
            $settings->set(ModuleParameterEnum::StudioDeliverables->value, '0');
            $checker->reset();
            $this->client->request('GET', (string) $font['url']);
            self::assertResponseIsSuccessful();

            $settings->set(ModuleParameterEnum::StudioSpaces->value, '0');
            $checker->reset();
            $this->client->request('GET', (string) $font['url']);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $settings->set(ModuleParameterEnum::StudioDeliverables->value, '1');
            $settings->set(ModuleParameterEnum::StudioSpaces->value, '1');
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
     * A new reading link, under this label, and its token.
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
}
