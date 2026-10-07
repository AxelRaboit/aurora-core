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
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function base64_decode;
use function json_decode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function sprintf;

/**
 * A Drive file that becomes a media library document.
 *
 * **The name is what would have been lost.** Google does not give it with
 * the content: its response carries an attachment without a `filename`, so
 * an import that does not ask for it again files "1BxY_…Kp3" in a client's
 * library, where nobody finds it again. The real uploader is wired in here
 * for that reason - a double would have returned whatever name it was
 * taught.
 *
 * It is also what makes the file attachable everywhere: once in the media
 * library, it is no longer a special case for items, notes or galleries.
 */
final class SpaceDriveImportTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        // The browser sets this header on every call, and public routes
        // require it: what protects them is a secret in the address, and an
        // address can be forwarded.
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
    }

    protected function tearDown(): void
    {
        foreach ([Document::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheFiledDocumentCarriesTheNameGoogleGives(): void
    {
        $space = $this->givenSpaceWithDrive();

        // A one-pixel PNG: the uploader refuses anything that is not a file it
        // knows how to handle, and made-up content would prove nothing.
        $pixel = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        $this->givenDrive([
            $this->token(),
            $this->listing('fichier-1'),
            new MockResponse((string) json_encode(['name' => 'Brief octobre.png', 'mimeType' => 'image/png']), [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
            new MockResponse($pixel, ['response_headers' => ['content-type' => 'image/png']]),
        ]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/fichier-1/import', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $document = $this->entityManager->getRepository(Document::class)->findAll()[0] ?? null;
        self::assertInstanceOf(Document::class, $document);
        self::assertSame('Brief octobre.png', $document->getOriginalName());
    }

    /**
     * A file the space's folder does not contain is not filed, even if the
     * service account can read it: it may belong to another client's Drive.
     */
    public function testAFileOutsideTheSpacesFolderIsNotImported(): void
    {
        $space = $this->givenSpaceWithDrive();
        $this->givenDrive([$this->token(), $this->listing('fichier-1')]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/fichier-d-un-autre/import', $space->getId()));

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->entityManager->getRepository(Document::class)->findAll());
    }

    /**
     * A Google document has no bytes to download.
     *
     * Refused with a message rather than with an empty document: it is the
     * only case where the screen must say something, and mistaking it for an
     * outage would send people looking for the cause in the settings.
     */
    public function testAFileGoogleWillNotServeIsRefusedAndFilesNothing(): void
    {
        $space = $this->givenSpaceWithDrive();

        $this->givenDrive([
            $this->token(),
            $this->listing('fichier-1'),
            new MockResponse((string) json_encode(['name' => 'Compte rendu', 'mimeType' => 'application/vnd.google-apps.document']), [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
            new MockResponse('', ['http_code' => 403]),
        ]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/fichier-1/import', $space->getId()));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->entityManager->getRepository(Document::class)->findAll());
    }

    /** Without a connected folder, the route does not exist for this space. */
    public function testAnImportIsNotFoundOnASpaceWithNoFolder(): void
    {
        $space = $this->givenSpaceWithDrive(folder: null);

        $this->givenDrive([$this->token()]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/fichier-1/import', $space->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** The space's folder as Google lists it. */
    private function listing(string ...$ids): MockResponse
    {
        return new MockResponse((string) json_encode(['files' => array_map(
            static fn (string $id): array => ['id' => $id, 'name' => $id, 'mimeType' => 'image/png', 'parents' => ['dossier']],
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
        // Without this, the kernel reboots on the next request and the
        // service set here disappears with it.
        $this->client->disableReboot();

        static::getContainer()->set(DriveClient::class, new DriveClient(
            new MockHttpClient($responses),
            new ArrayAdapter(),
            new NullLogger(),
        ));
    }

    private function givenSpaceWithDrive(?string $folder = 'dossier-partage'): CustomerSpace
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

        $customer = new Customer();
        $customer
            ->setLegalName('Client du Drive')
            ->setSiret('73282932000074')
            ->setContractualEmail('drive@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace du Drive',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        $space->setDriveFolderId($folder);
        $this->entityManager->flush();

        return $space;
    }
}
