<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

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
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function basename;
use function bin2hex;
use function json_decode;
use function parse_url;
use function random_bytes;
use function sprintf;
use function str_replace;

use const PHP_URL_PATH;

/**
 * Presentations kept in a client space: created there (from scratch, from a
 * Studio template or from a text), copied both ways, composed under the
 * space's rights, and read by the client when shown to them.
 *
 * What would break quietly: a copy that loses its slides, notes or look, a
 * space member writing slides without the right to edit the space, the
 * speaker notes on the client's page, a hidden presentation reachable from
 * the space link, or a slide picture the media library thinks unused.
 */
final class SpaceSlidesTest extends IntegrationTestCase
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
        foreach ([DeliverableLink::class, Deliverable::class, SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        foreach ($this->documents as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s d WHERE d.id = :id', Document::class))->setParameter('id', $id)->execute();
        }

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    /** A space creates a presentation, which opens in the slide editor inside the space shell. */
    public function testASpaceCreatesAPresentationThatOpensInTheSlideEditor(): void
    {
        $space = $this->givenSpace();
        $id = $this->createInSpace($space, ['title' => 'Comité de pilotage', 'format' => 'slides']);

        $deliverable = $this->find($id);
        self::assertSame(DeliverableFormatEnum::Slides, $deliverable->getFormat());
        self::assertSame($space->getId(), $deliverable->getSpace()?->getId());
        self::assertFalse($deliverable->isVisibleToClient(), 'born hidden, like every space deliverable');

        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d', $space->getId(), $id));
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('DeliverableSlidesEditorApp', $body);
        // The props are JSON in an attribute: slashes come escaped.
        self::assertStringContainsString(sprintf('\\/workspace\\/%d\\/deliverables\\/%d\\/slides\\/create', $space->getId(), $id), $body);
        self::assertStringNotContainsString('\\/suite\\/studio\\/deliverables\\/'.$id.'\\/', $body);

        // The page still opens in the page editor.
        $page = $this->createInSpace($space, ['title' => 'Bilan']);
        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d', $space->getId(), $page));
        self::assertStringNotContainsString('DeliverableSlidesEditorApp', (string) $this->client->getResponse()->getContent());

        // An unknown format is refused.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => 'Vidéo', 'format' => 'video']);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * « Partir d'un modèle » from a space takes a Studio template of the
     * format asked, slides, notes, theme and style included; a template of
     * the other format counts for nothing.
     */
    public function testASpaceStartsFromAStudioTemplate(): void
    {
        $template = $this->studioTemplate();
        $space = $this->givenSpace();

        $id = $this->createInSpace($space, ['title' => 'Lancement Fabre', 'format' => 'slides', 'fromTemplateId' => $template]);
        $copy = $this->find($id);
        self::assertCount(2, $copy->getSlides());
        self::assertSame('Bienvenue', $copy->getSlides()->first()->getContent()['title']);
        self::assertSame('Se présenter.', $copy->getSlides()->first()->getSpeakerNotes());
        self::assertSame(DeckThemeEnum::Paper, $copy->getSlideTheme());
        self::assertSame('Studio', $copy->getSlideStyle()['footerText']);
        self::assertFalse($copy->isTemplate());
        self::assertSame('Lancement Fabre', $copy->getTitle());

        $page = $this->find($this->createInSpace($space, ['title' => 'Une page', 'format' => 'page', 'fromTemplateId' => $template]));
        self::assertSame(DeliverableFormatEnum::Page, $page->getFormat());
        self::assertCount(0, $page->getSlides());

        // The tab offers the templates the reader may read.
        $this->client->request('GET', sprintf('/workspace/%d', $space->getId()));
        self::assertStringContainsString('Trame de lancement', (string) $this->client->getResponse()->getContent());
    }

    /** A pasted text becomes a presentation of the space; a text with nothing in it leaves nothing behind. */
    public function testASpaceImportsATextIntoAPresentation(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/import', $space->getId()), [
            'title' => 'Ordre du jour',
            'blocks' => [
                ['type' => 'header', 'data' => ['text' => 'Points ouverts', 'level' => 2]],
                ['type' => 'paragraph', 'data' => ['text' => 'Le calendrier de novembre.']],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $deliverable = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertSame(DeliverableFormatEnum::Slides, $deliverable->getFormat());
        self::assertSame($space->getId(), $deliverable->getSpace()?->getId());
        self::assertNotEmpty($deliverable->getSlides());

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/import', $space->getId()), ['title' => 'Vide', 'blocks' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, (int) $this->entityManager->createQuery(sprintf('SELECT COUNT(d.id) FROM %s d', Deliverable::class))->getSingleScalarResult());
    }

    /** A Studio presentation copies into a space, and a space presentation back into Studio, whole. */
    public function testAPresentationIsCopiedBothWays(): void
    {
        $template = $this->studioTemplate();
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $template), ['spaceId' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $editPath = (string) $this->json()['editPath'];
        self::assertStringStartsWith(sprintf('/workspace/%d/deliverables/', $space->getId()), $editPath);
        $inSpace = $this->find((int) basename($editPath));
        self::assertSame($space->getId(), $inSpace->getSpace()?->getId());
        self::assertCount(2, $inSpace->getSlides());
        self::assertSame('Se présenter.', $inSpace->getSlides()->first()->getSpeakerNotes());
        self::assertSame(DeckThemeEnum::Paper, $inSpace->getSlideTheme());
        self::assertSame('Studio', $inSpace->getSlideStyle()['footerText']);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/copy-to-studio', $space->getId(), $inSpace->getId()));
        self::assertResponseIsSuccessful();
        $back = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertNull($back->getSpace());
        self::assertSame(DeliverableFormatEnum::Slides, $back->getFormat());
        self::assertCount(2, $back->getSlides());
        self::assertSame('Bienvenue', $back->getSlides()->first()->getContent()['title']);
        self::assertSame(DeckThemeEnum::Paper, $back->getSlideTheme());
    }

    /**
     * The space's rights: a member who may view the space reads, presents
     * and prints but writes nothing (403); somebody outside the team does not
     * even learn the presentation exists (404). A Studio presentation is never
     * written through a space's address, nor a space's through Studio's.
     */
    public function testTheSpaceRightsApplyToTheSlideEditor(): void
    {
        $viewer = $this->accountWith(['studio.spaces.view']);
        $stranger = $this->accountWith(['studio.spaces.view', 'studio.spaces.edit']);
        $space = $this->givenSpace([$viewer]);
        $id = $this->createInSpace($space, ['title' => 'Revue', 'format' => 'slides']);
        $slide = $this->addSlide($space, $id, 'title');
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/%d/update', $space->getId(), $id, $slide), [
            'content' => ['title' => 'Revue du mois'],
            'speakerNotes' => 'Commencer par les bonnes nouvelles.',
        ]);
        self::assertResponseIsSuccessful();

        $studio = $this->studioTemplate();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/create', $space->getId(), $studio), ['layout' => 'title']);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $id), ['layout' => 'title']);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($viewer, 'admin');
        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d', $space->getId(), $id));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/presenter', $space->getId(), $id));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Commencer par les bonnes nouvelles.', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/print', $space->getId(), $id));
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/create', $space->getId(), $id), ['layout' => 'title']);
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/appearance', $space->getId(), $id), ['theme' => 'ink']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($stranger, 'admin');
        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/presenter', $space->getId(), $id));
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/create', $space->getId(), $id), ['layout' => 'title']);
        self::assertResponseStatusCodeSame(404);

        self::assertCount(1, $this->find($id)->getSlides());
    }

    /**
     * The client reads a presentation shown to them on their space page,
     * without the speaker notes; a hidden one is neither listed nor reachable.
     */
    public function testTheClientReadsAShownPresentationWithoutTheNotes(): void
    {
        $space = $this->givenSpace();
        $shown = $this->createInSpace($space, ['title' => 'Bilan visible', 'format' => 'slides']);
        $hidden = $this->createInSpace($space, ['title' => 'Brouillon secret', 'format' => 'slides']);
        $slide = $this->addSlide($space, $shown, 'title');
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/%d/update', $space->getId(), $shown, $slide), [
            'content' => ['title' => 'Trois mois de travail'],
            'speakerNotes' => 'Ne pas citer le chiffre de mars.',
        ]);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $shown), ['visible' => true]);
        self::assertResponseIsSuccessful();

        // The studio's preview reads like the client's page: no notes.
        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/preview', $space->getId(), $shown));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('PublicDeckApp', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Ne pas citer', (string) $this->client->getResponse()->getContent());

        $link = $this->givenAccessLink($space);
        $base = sprintf('/spaces/%s/%s', $link->getSelector(), (string) $link->getPlainToken());
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Bilan visible', $page);
        self::assertStringNotContainsString('Brouillon secret', $page);

        $this->client->request('GET', sprintf('%s/deliverables/%d', $base, $shown));
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('PublicDeckApp', $body);
        self::assertStringContainsString('Trois mois de travail', $body);
        self::assertStringNotContainsString('Ne pas citer', $body);
        self::assertStringNotContainsString('speakerNotes', $body);
        self::assertStringContainsString(str_replace('/', '\\/', $base), $body, 'it leads back to the space');

        $this->client->request('GET', sprintf('%s/deliverables/%d', $base, $hidden));
        self::assertResponseStatusCodeSame(404);
    }

    /** A reading link of a space presentation shows its slides, without the notes, hidden or not. */
    public function testAReadingLinkOfASpacePresentationShowsItsSlides(): void
    {
        $space = $this->givenSpace();
        $id = $this->createInSpace($space, ['title' => 'Lu par lien', 'format' => 'slides']);
        $slide = $this->addSlide($space, $id, 'title');
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/%d/update', $space->getId(), $id, $slide), [
            'content' => ['title' => 'Bonjour'],
            'speakerNotes' => 'Note privée.',
        ]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/links/create', $space->getId(), $id), ['label' => 'prospect']);
        self::assertResponseIsSuccessful();
        $token = basename((string) parse_url((string) $this->json()['links'][0]['url'], PHP_URL_PATH));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/deliverables/'.$token);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('PublicDeckApp', $body);
        self::assertStringContainsString('Bonjour', $body);
        self::assertStringNotContainsString('Note privée.', $body);
    }

    /** A picture on a slide of a space presentation counts in the media library, and names its space. */
    public function testThePicturesOfASpacePresentationAreCounted(): void
    {
        $picture = $this->picture('vitrine.jpg');
        $space = $this->givenSpace();
        $id = $this->createInSpace($space, ['title' => 'Avec une image', 'format' => 'slides']);
        $slide = $this->addSlide($space, $id, 'image');
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/%d/update', $space->getId(), $id, $slide), ['content' => ['mediaId' => $picture]]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $provider = self::getContainer()->get(DeliverableDocumentUsageProvider::class);
        self::assertSame(1, $provider->countUsagesFor([$picture])[$picture] ?? 0);
        $usages = $provider->findUsages($picture);
        self::assertCount(1, $usages);
        self::assertSame(sprintf('/workspace/%d/deliverables/%d', $space->getId(), $id), $usages[0]['href']);
    }

    /** A Studio presentation marked as a template, with two slides, a note and a look. */
    private function studioTemplate(): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Trame de lancement', 'scope' => 'shared', 'format' => 'slides']);
        self::assertResponseIsSuccessful();
        $id = (int) basename((string) $this->json()['editPath']);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $id), ['layout' => 'title']);
        $slide = (int) $this->json()['slide']['id'];
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $id, $slide), [
            'content' => ['title' => 'Bienvenue'],
            'speakerNotes' => 'Se présenter.',
        ]);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/create', $id), ['layout' => 'end']);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/appearance', $id), ['theme' => 'paper', 'style' => ['footerText' => 'Studio']]);
        self::assertResponseIsSuccessful();

        $deliverable = $this->find($id);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/update', $id), [
            'title' => $deliverable->getTitle(),
            'locale' => $deliverable->getLocale(),
            'scope' => 'shared',
            'template' => true,
        ]);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @param array<string, mixed> $payload */
    private function createInSpace(CustomerSpace $space, array $payload): int
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), $payload);
        self::assertResponseIsSuccessful();

        return (int) basename((string) $this->json()['editPath']);
    }

    private function addSlide(CustomerSpace $space, int $id, string $layout): int
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/slides/create', $space->getId(), $id), ['layout' => $layout]);
        self::assertResponseIsSuccessful();

        return (int) $this->json()['slide']['id'];
    }

    /** @param list<User> $members */
    private function givenSpace(array $members = []): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client présentation')->setContractualEmail(bin2hex(random_bytes(4)).'@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $team = [['userId' => $this->admin->getId(), 'role' => 'lead']];
        foreach ($members as $member) {
            $team[] = ['userId' => $member->getId(), 'role' => 'member'];
        }

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace présentation '.bin2hex(random_bytes(2)),
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
            'members' => $team,
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenAccessLink(CustomerSpace $space): SpaceAccessLinkInterface
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return self::getContainer()->get(SpaceAccessLinkManagerInterface::class)
            ->issue($fresh, 'client@example.test', 'Le client', 30, true, true);
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('espace-diapos-'.bin2hex(random_bytes(5)).'@aurora.app')
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

    private function picture(string $name): int
    {
        $document = new Document();
        $document
            ->setTitle($name)
            ->setOriginalName($name)
            ->setFilePath('ged/2026/10/'.bin2hex(random_bytes(4)).'-'.$name)
            ->setMimeType('image/jpeg')
            ->setStatus(DocumentStatusEnum::Published);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = (int) $document->getId();

        return (int) $document->getId();
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
}
