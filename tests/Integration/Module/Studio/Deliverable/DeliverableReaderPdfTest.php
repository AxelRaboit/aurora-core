<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
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
 * The PDF of a deliverable is the author's to offer.
 *
 * Until now only the author's own preview could ask for the printable page
 * (`?print=1`); a client on a reading link or in their space had no way to keep
 * a copy. The setting "Let the reader download a PDF" is off by default, and
 * while it is off the request is simply ignored: the page reads as usual.
 */
final class DeliverableReaderPdfTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', DeliverableLink::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deliverable::class))->execute();

        parent::tearDown();
    }

    public function testWhileTheSettingIsOffTheRequestIsIgnored(): void
    {
        $token = $this->givenLink(readerPdf: false);

        $this->client->request('GET', '/deliverables/'.$token);
        self::assertStringNotContainsString('data-reader-pdf', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/deliverables/'.$token.'?print=1');
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-slides-print', $page, 'The printable version is not served without the author\'s say-so.');
        self::assertStringNotContainsString('data-reader-pdf', $page);
    }

    public function testWhenAllowedTheReaderGetsTheButtonAndThePrintableVersion(): void
    {
        $token = $this->givenLink(readerPdf: true);

        $this->client->request('GET', '/deliverables/'.$token);
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-reader-pdf', $page);
        self::assertStringContainsString('href="?print=1"', $page);
        self::assertStringNotContainsString('data-slides-print', $page);

        $this->client->request('GET', '/deliverables/'.$token.'?print=1');
        self::assertResponseIsSuccessful();
        $printed = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-slides-print', $printed);
        self::assertStringNotContainsString('data-reader-pdf', $printed, 'No button on the page that is being printed.');
    }

    public function testTheEditorPreviewDoesNotCarryTheButton(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/deliverables/grid-preview', [
            'frame' => true,
            'locale' => 'fr',
            'title' => 'Aperçu',
            'appearance' => ['readerPdf' => true],
            'layout' => ['enabled' => true, 'zones' => [['id' => 'a', 'type' => 'text', 'span' => ['base' => 48, 'md' => 48, 'lg' => 48]]]],
            'content' => ['zones' => ['a' => ['blocks' => [['type' => 'header', 'data' => ['text' => 'Objectifs', 'level' => 2]]]]]],
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['html'];
        self::assertStringContainsString('Objectifs', $html, 'The whole page was rendered, not an empty answer.');
        self::assertStringNotContainsString('data-reader-pdf', $html);
    }

    public function testOnlyAWholeTrueTurnsItOn(): void
    {
        foreach (['1', 'true', 1, 'yes', null] as $value) {
            self::assertFalse(DeliverableAppearance::normalize(['readerPdf' => $value])['readerPdf'], var_export($value, true));
        }

        self::assertTrue(DeliverableAppearance::normalize(['readerPdf' => true])['readerPdf']);
        self::assertFalse(DeliverableAppearance::normalize([])['readerPdf'], 'Off by default.');
    }

    private function givenLink(bool $readerPdf): string
    {
        $this->client->jsonRequest('POST', '/backend/studio/deliverables/create', ['title' => 'Pour le client', 'scope' => 'shared']);
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['shared'][0]['id'];

        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        $deliverable
            ->setGridLayout(['enabled' => true, 'zones' => [['id' => 'a', 'type' => 'text', 'span' => ['base' => 48, 'md' => 48, 'lg' => 48]]]])
            ->setGridContent(['zones' => ['a' => ['blocks' => [['type' => 'header', 'data' => ['text' => 'Objectifs', 'level' => 2]]]]]])
            ->setAppearance(['readerPdf' => $readerPdf]);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/deliverables/%d/links/create', $id), []);
        self::assertResponseIsSuccessful();

        $this->client->loginUser(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']), 'admin');
        $url = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['links'][0]['url'];

        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
