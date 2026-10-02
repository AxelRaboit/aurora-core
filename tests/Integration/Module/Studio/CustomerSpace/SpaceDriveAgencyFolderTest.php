<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettingEnum;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettings;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function array_shift;
use function base64_decode;
use function json_decode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function sprintf;
use function str_contains;
use function urldecode;

/**
 * The agency's own Drive folder, read from any space.
 *
 * One folder for the whole install - templates, a brand guide, what the team
 * consults while working for any client - next to the client's folder in a
 * space's Drive tab. What these tests hold: it lists *that* folder and not the
 * client's, it files into the space it was opened from, its containment check
 * is its own, and without a folder named the routes do not exist.
 */
final class SpaceDriveAgencyFolderTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
    }

    protected function tearDown(): void
    {
        foreach ([Document::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheAgencyListingReadsTheAgencyFolderAndNotTheClients(): void
    {
        $space = $this->givenSpace(agencyFolder: 'dossier-agence-0001');
        $this->givenDrive([$this->token(), $this->listing('dossier-agence-0001', 'charte')]);

        $this->client->request('GET', sprintf('/workspace/%d/drive/agency', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->wasAsked('dossier-agence-0001'));
        self::assertFalse($this->wasAsked('dossier-client-0001'));
    }

    public function testAFileOfTheAgencyFolderIsFiledInTheSpaceItWasOpenedFrom(): void
    {
        $space = $this->givenSpace(agencyFolder: 'dossier-agence-0001');

        $pixel = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        $this->givenDrive([
            $this->token(),
            $this->listing('dossier-agence-0001', 'charte'),
            new MockResponse((string) json_encode(['name' => 'Charte.png', 'mimeType' => 'image/png']), [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
            new MockResponse($pixel, ['response_headers' => ['content-type' => 'image/png']]),
        ]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/agency/charte/import', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $document = $this->entityManager->getRepository(Document::class)->findAll()[0] ?? null;
        self::assertInstanceOf(Document::class, $document);
        self::assertSame('Charte.png', $document->getOriginalName());
    }

    /**
     * The agency route checks against the agency folder: a file of the
     * client's folder does not pass through it, nor one from anywhere else.
     */
    public function testAFileOutsideTheAgencyFolderIsNotImported(): void
    {
        $space = $this->givenSpace(agencyFolder: 'dossier-agence-0001');
        $this->givenDrive([$this->token(), $this->listing('dossier-agence-0001', 'charte')]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/agency/fichier-client/import', $space->getId()));

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->entityManager->getRepository(Document::class)->findAll());
    }

    /**
     * Without a folder named, the listing says so - like a space with no
     * client folder - and asks Google nothing; a file route does not exist.
     */
    public function testWithoutAnAgencyFolderNothingIsReadOrFiled(): void
    {
        $space = $this->givenSpace(agencyFolder: null);
        $this->givenDrive([$this->token()]);

        $this->client->request('GET', sprintf('/workspace/%d/drive/agency', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $listing = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($listing['folderId']);
        self::assertSame([], $listing['files']);
        self::assertSame([], $this->requested);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/agency/charte/import', $space->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** Pasted as its address, kept as its id: the same rule as a space's folder. */
    public function testTheSettingsTakeTheFolderAddressAndKeepItsId(): void
    {
        $this->givenSpace(agencyFolder: null);

        $this->client->jsonRequest('POST', '/backend/studio/drive/settings', [
            'enabled' => true,
            'agencyFolderId' => 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOp?usp=sharing',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('1AbCdEfGhIjKlMnOp', static::getContainer()->get(DriveSettings::class)->agencyFolderId());

        $state = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('1AbCdEfGhIjKlMnOp', $state['agencyFolderId']);
    }

    public function testTheSettingsRefuseWhatIsNotAFolder(): void
    {
        $this->givenSpace(agencyFolder: 'dossier-agence-0001');

        $this->client->jsonRequest('POST', '/backend/studio/drive/settings', [
            'enabled' => true,
            'agencyFolderId' => 'pas un dossier',
        ]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('dossier-agence-0001', static::getContainer()->get(DriveSettings::class)->agencyFolderId());
    }

    public function testAnEmptyFieldUnlinksTheAgencyFolder(): void
    {
        $this->givenSpace(agencyFolder: 'dossier-agence-0001');

        $this->client->jsonRequest('POST', '/backend/studio/drive/settings', ['enabled' => true, 'agencyFolderId' => '']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull(static::getContainer()->get(DriveSettings::class)->agencyFolderId());
    }

    /**
     * Le dossier de l'agence se règle depuis les réglages d'un espace, dans
     * une fenêtre : l'écran reçoit l'adresse où l'enregistrer.
     */
    public function testTheSpaceSettingsCanSetTheAgencyFolderInPlace(): void
    {
        $space = $this->givenSpace(agencyFolder: null);

        $this->client->setServerParameter('HTTP_X-Requested-With', '');
        $this->client->request('GET', sprintf('/workspace/%d?view=settings', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertMatchesRegularExpression(
            '#driveAgencyFolderPath&quot;:&quot;(\\\\)?/backend(\\\\)?/studio(\\\\)?/drive(\\\\)?/settings(\\\\)?/agency-folder&quot;#',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * Ce geste ne touche que le dossier. L'enregistrement général lit
     * `enabled` absent comme « éteint » : passer par lui depuis un espace
     * éteindrait le Drive de toute l'installation.
     */
    public function testSettingTheAgencyFolderAloneLeavesTheDriveSwitchedOn(): void
    {
        $this->givenSpace(agencyFolder: null);
        // Read again after each request: the kernel reboots between them,
        // and a service kept from before answers from its own cache.
        $settings = static fn (): DriveSettings => static::getContainer()->get(DriveSettings::class);

        $this->client->jsonRequest('POST', '/backend/studio/drive/settings/agency-folder', [
            'agencyFolderId' => 'https://drive.google.com/drive/folders/dossier-agence-0002?usp=sharing',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('dossier-agence-0002', $settings()->agencyFolderId());
        self::assertTrue($settings()->isEnabled(), 'the Drive is still on');

        $this->client->jsonRequest('POST', '/backend/studio/drive/settings/agency-folder', ['agencyFolderId' => 'https://example.com/pas-un-dossier']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('dossier-agence-0002', $settings()->agencyFolderId(), 'a refused address changes nothing');

        $this->client->jsonRequest('POST', '/backend/studio/drive/settings/agency-folder', ['agencyFolderId' => '']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($settings()->agencyFolderId());
        self::assertTrue($settings()->isEnabled());
    }

    private function wasAsked(string $folderId): bool
    {
        foreach ($this->requested as $url) {
            if (str_contains(urldecode($url), sprintf("'%s' in parents", $folderId))) {
                return true;
            }
        }

        return false;
    }

    private function listing(string $parent, string ...$ids): MockResponse
    {
        return new MockResponse((string) json_encode(['files' => array_map(
            static fn (string $id): array => ['id' => $id, 'name' => $id, 'mimeType' => 'image/png', 'parents' => [$parent]],
            $ids,
        )]), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function token(): MockResponse
    {
        return new MockResponse((string) json_encode(['access_token' => 'jeton-google', 'expires_in' => 3600]), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** @param list<MockResponse> $responses */
    private function givenDrive(array $responses): void
    {
        $this->client->disableReboot();

        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requested[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        static::getContainer()->set(DriveClient::class, new DriveClient($http, new ArrayAdapter(), new NullLogger()));
    }

    private function givenSpace(?string $agencyFolder): CustomerSpace
    {
        $container = static::getContainer();
        $settings = $container->get(SettingRepository::class);
        $encryption = $container->get(EncryptionServiceInterface::class);

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);

        $private = '';
        openssl_pkey_export($resource, $private);

        $settings->set(DriveSettingEnum::ServiceAccount->value, $encryption->encrypt((string) json_encode([
            'type' => 'service_account',
            'client_email' => 'aurora@projet.iam.gserviceaccount.com',
            'private_key' => $private,
        ])));
        $settings->set(DriveSettingEnum::Enabled->value, '1');
        $settings->set(DriveSettingEnum::AgencyFolder->value, $agencyFolder ?? '');

        $customer = new Customer();
        $customer
            ->setLegalName('Client du Drive')
            ->setSiret('73282932000074')
            ->setContractualEmail('drive@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace du Drive',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        $space->setDriveFolderId('dossier-client-0001');
        $this->entityManager->flush();

        return $space;
    }
}
