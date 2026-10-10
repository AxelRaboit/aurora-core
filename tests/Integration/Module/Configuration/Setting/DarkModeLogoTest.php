<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Configuration\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_reverse;
use function bin2hex;
use function random_bytes;
use function str_replace;

/**
 * The suite's logo in dark mode (10/10/2026).
 *
 * A logo without a background drawn in a dark colour vanished on the dark
 * side menu, and the only way out was a logo on a tile in both modes. An
 * optional second logo now takes its place in dark mode; empty, the site's
 * logo serves in both, as before.
 */
final class DarkModeLogoTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SettingRepository $settingRepository;

    /** @var array<string, ?string> */
    private array $before = [];

    /** @var list<DocumentInterface> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->settingRepository = static::getContainer()->get(SettingRepository::class);

        foreach ([ApplicationParameterEnum::LogoMediaId, ApplicationParameterEnum::LogoDarkMediaId] as $parameter) {
            $this->before[$parameter->value] = $this->settingRepository->get($parameter->value);
        }

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $key => $value) {
            $this->settingRepository->set($key, $value);
        }

        foreach (array_reverse($this->created) as $document) {
            $managed = $this->entityManager->find(Document::class, $document->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheSuiteReceivesBothLogos(): void
    {
        $light = $this->givenDocument('Logo');
        $dark = $this->givenDocument('Logo sombre');
        $this->settingRepository->set(ApplicationParameterEnum::LogoMediaId->value, (string) $light->getId());
        $this->settingRepository->set(ApplicationParameterEnum::LogoDarkMediaId->value, (string) $dark->getId());

        $this->client->request('GET', '/suite');

        self::assertResponseIsSuccessful();
        // The menu's props are JSON in an attribute: slashes come escaped.
        $page = str_replace('\\/', '/', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('siteLogoUrl&quot;:&quot;/suite/ged/files/'.$light->getFilePath(), $page);
        self::assertStringContainsString('siteLogoDarkUrl&quot;:&quot;/suite/ged/files/'.$dark->getFilePath(), $page);
    }

    /** Without one, nothing changes: the site's logo serves in both modes. */
    public function testWithoutADarkLogoThePageNamesNone(): void
    {
        $this->settingRepository->set(ApplicationParameterEnum::LogoDarkMediaId->value, '');

        $this->client->request('GET', '/suite');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('&quot;siteLogoDarkUrl&quot;:&quot;&quot;', (string) $this->client->getResponse()->getContent());
    }

    /** The library must not offer to throw away a picture the suite shows. */
    public function testTheDarkLogoCountsAsAUse(): void
    {
        $dark = $this->givenDocument('Logo sombre');
        $this->settingRepository->set(ApplicationParameterEnum::LogoDarkMediaId->value, (string) $dark->getId());

        $usage = static::getContainer()->get(DocumentUsageService::class)->findUsages((int) $dark->getId());

        self::assertSame(1, $usage['total']);
        self::assertSame('configuration.setting', $usage['groups'][0]['type']);
    }

    private function givenDocument(string $title): DocumentInterface
    {
        $document = new Document();
        $document
            ->setTitle($title.' '.bin2hex(random_bytes(3)))
            ->setOriginalName('logo.png')
            ->setFilePath('ged/2026/10/'.bin2hex(random_bytes(8)).'.png')
            ->setMimeType('image/png');

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->created[] = $document;

        return $document;
    }
}
