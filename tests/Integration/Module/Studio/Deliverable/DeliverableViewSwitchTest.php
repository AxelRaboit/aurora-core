<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function basename;
use function json_decode;
use function parse_url;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * The reader picks the view: a presentation can be read as a web page, and a
 * page shown as a presentation.
 *
 * The author's choice stays the default. `?view=page` or `?view=slides` asks
 * for the other one, anything else is ignored; both views share the section
 * addresses (`#diapo-N`), so switching keeps one's place. Switching is not a
 * new opening of the link.
 */
final class DeliverableViewSwitchTest extends IntegrationTestCase
{
    private const array FULL = ['base' => 48, 'md' => 48, 'lg' => 48];

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']), 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', DeliverableLink::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deliverable::class))->execute();

        parent::tearDown();
    }

    public function testAPresentationCanBeReadAsAPageAtTheSameSections(): void
    {
        $token = $this->givenLink(['display' => 'slides'], sections: 3);

        $slides = $this->read($token);
        self::assertStringContainsString('class="aurora-slides"', $slides);
        self::assertStringContainsString('data-view-switch="page"', $slides);
        self::assertStringContainsString('href="?view=page"', $slides);

        $page = $this->read($token, '?view=page');
        self::assertStringNotContainsString('class="aurora-slides"', $page);
        self::assertStringContainsString('data-view-switch="slides"', $page);
        // Each section's first zone carries its number and the address both views share.
        foreach ([1, 2, 3] as $section) {
            self::assertStringContainsString(sprintf('data-section="%d"', $section), $page);
            self::assertStringContainsString(sprintf('id="diapo-%d"', $section), $page);
        }
    }

    public function testAPageCanBeShownAsAPresentation(): void
    {
        $token = $this->givenLink([], sections: 2);

        $page = $this->read($token);
        self::assertStringNotContainsString('class="aurora-slides"', $page);
        self::assertStringContainsString('href="?view=slides"', $page);

        $slides = $this->read($token, '?view=slides');
        self::assertStringContainsString('class="aurora-slides"', $slides);
        self::assertSame(2, mb_substr_count($slides, 'data-slide '));
    }

    public function testAnythingButPageOrSlidesLeavesTheAuthorsChoice(): void
    {
        $token = $this->givenLink(['display' => 'slides'], sections: 2);

        foreach (['?view=bogus', '?view=', '?view[]=page', '?view=PAGE'] as $query) {
            self::assertStringContainsString('class="aurora-slides"', $this->read($token, $query), $query);
        }
    }

    public function testSwitchingViewsIsNotANewOpening(): void
    {
        $token = $this->givenLink(['display' => 'slides'], sections: 2);

        $this->read($token);
        $this->read($token, '?view=page');
        $this->read($token, '?view=slides');
        $this->read($token, '?view=page');

        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->getRepository(DeliverableLink::class)->findOneBy([])->getOpenCount());

        // A plain visit again is an opening again.
        $this->read($token);
        $this->entityManager->clear();
        self::assertSame(2, $this->entityManager->getRepository(DeliverableLink::class)->findOneBy([])->getOpenCount());
    }

    public function testASingleSectionOffersNoOtherView(): void
    {
        $token = $this->givenLink(['display' => 'slides'], sections: 1);

        self::assertStringNotContainsString('data-view-switch', $this->read($token));
        self::assertStringNotContainsString('data-view-switch', $this->read($token, '?view=page'));
    }

    public function testThePrintableVersionStaysOneSlidePerPageWithoutTheSwitch(): void
    {
        $token = $this->givenLink(['display' => 'page', 'readerPdf' => true], sections: 2);

        $printed = $this->read($token, '?print=1&view=page');
        self::assertStringContainsString('data-slides-print', $printed);
        self::assertStringNotContainsString('data-view-switch', $printed);
    }

    public function testTheAuthorsPreviewOffersTheSwitchToo(): void
    {
        $this->givenLink(['display' => 'slides'], sections: 2);
        $id = (int) $this->entityManager->getRepository(Deliverable::class)->findOneBy([])->getId();

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d/preview?view=page', $id));
        self::assertResponseIsSuccessful();
        $preview = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('class="aurora-slides"', $preview);
        self::assertStringContainsString('data-view-switch="slides"', $preview);
    }

    public function testAnAnchorTheAuthorSetIsKept(): void
    {
        $token = $this->givenLink([], sections: 2, anchor: 'nos-objectifs');

        $page = $this->read($token);
        self::assertStringContainsString('id="nos-objectifs"', $page);
        self::assertStringNotContainsString('id="diapo-2"', $page);
        // The number is still there, for the script to find the section by.
        self::assertStringContainsString('data-section="2"', $page);
    }

    private function read(string $token, string $query = ''): string
    {
        $this->client->request('GET', '/deliverables/'.$token.$query);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** @param array<string, mixed> $appearance */
    private function givenLink(array $appearance, int $sections, ?string $anchor = null): string
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Bascule', 'scope' => 'shared']);
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['shared'][0]['id'];

        $zones = [];
        $content = [];
        for ($i = 1; $i <= $sections; ++$i) {
            $zone = ['id' => 'z'.$i, 'type' => 'text', 'span' => self::FULL];
            if (null !== $anchor && 2 === $i) {
                $zone['anchor'] = $anchor;
            }
            $zones[] = $zone;
            $content['z'.$i] = ['blocks' => [['type' => 'header', 'data' => ['text' => 'Section '.$i, 'level' => 2]], ['type' => 'paragraph', 'data' => ['text' => 'Texte '.$i]]]];
        }

        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        $deliverable
            ->setGridLayout(['enabled' => true, 'zones' => $zones])
            ->setGridContent(['zones' => $content])
            ->setAppearance($appearance);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/links/create', $id), []);
        self::assertResponseIsSuccessful();
        $url = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['links'][0]['url'];

        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
