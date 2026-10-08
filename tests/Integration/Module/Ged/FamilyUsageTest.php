<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\Document\View\DocumentsViewBuilder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function array_reverse;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

/**
 * Where a family is used, by kind of source.
 *
 * The listing and the family strip say which member is used and by what -
 * "Original : 1 réglage du site · rouge : …" - so every count now comes with
 * its kind. And the site settings that hold a picture are a kind of their
 * own: the favicon of the site used to read "Inutilisé".
 */
final class FamilyUsageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SettingRepository $settingRepository;

    private ?string $faviconBefore = null;

    /** @var list<DocumentInterface> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->settingRepository = static::getContainer()->get(SettingRepository::class);
        $this->faviconBefore = $this->settingRepository->get(ApplicationParameterEnum::FaviconMediaId->value);

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->settingRepository->set(ApplicationParameterEnum::FaviconMediaId->value, $this->faviconBefore);

        foreach (array_reverse($this->created) as $document) {
            $managed = $this->entityManager->find(Document::class, $document->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheFaviconOfTheSiteIsAUse(): void
    {
        $icon = $this->givenDocument('Favicon');
        $this->settingRepository->set(ApplicationParameterEnum::FaviconMediaId->value, (string) $icon->getId());

        $usage = static::getContainer()->get(DocumentUsageService::class)->findUsages((int) $icon->getId());

        self::assertSame(1, $usage['total']);
        self::assertSame('configuration.setting', $usage['groups'][0]['type']);
        self::assertStringContainsString('/suite/configuration/settings/branding', (string) $usage['groups'][0]['items'][0]['href']);
    }

    public function testTheListingSaysWhatKindOfSourceUsesEachMember(): void
    {
        $green = $this->givenDocument('Visuel');
        $red = $this->givenDocument('Visuel rouge', $green, 'rouge');
        $this->settingRepository->set(ApplicationParameterEnum::FaviconMediaId->value, (string) $red->getId());

        $row = $this->row((int) $green->getId());

        self::assertSame([], $row['usageByType'], 'the original itself is used by nothing');
        $member = $row['alternates'][0];
        self::assertSame($red->getId(), $member['id']);
        self::assertSame(1, $member['usageCount']);
        self::assertSame(['configuration.setting' => 1], $member['usageByType']);
    }

    public function testTheFamilyStripGetsTheSameAnswer(): void
    {
        $green = $this->givenDocument('Visuel');
        $red = $this->givenDocument('Visuel rouge', $green, 'rouge');
        $this->settingRepository->set(ApplicationParameterEnum::FaviconMediaId->value, (string) $red->getId());

        $this->client->request('GET', sprintf('/suite/ged/documents/%d/alternates', $green->getId()));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(0, $data['original']['usageCount']);
        self::assertSame([], $data['original']['usageByType']);
        self::assertSame(['configuration.setting' => 1], $data['alternates'][0]['usageByType']);
        self::assertSame([$red->getId()], array_column($data['alternates'], 'id'));
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $payload = static::getContainer()->get(DocumentsViewBuilder::class)->buildListPayload(
            new PaginationRequest(page: 1, limit: 200, search: null),
            originalsOnly: true,
        );

        foreach ($payload['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        self::fail('the original is on the first page');
    }

    private function givenDocument(string $title, ?DocumentInterface $original = null, ?string $label = null): DocumentInterface
    {
        $document = new Document();
        $document
            ->setTitle($title.' '.bin2hex(random_bytes(3)))
            ->setOriginalName('visuel.png')
            ->setFilePath('ged/2026/09/'.bin2hex(random_bytes(8)).'.png')
            ->setMimeType('image/png')
            ->setOriginal($original)
            ->setAlternateLabel($label);

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->created[] = $document;

        return $document;
    }
}
