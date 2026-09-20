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

use function base64_decode;
use function json_decode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function sprintf;

/**
 * Un fichier du Drive qui devient un document de la médiathèque.
 *
 * **Le nom est ce qui se serait perdu.** Google ne le donne pas avec le
 * contenu : sa réponse porte une pièce jointe sans `filename`, donc un import
 * qui ne le redemande pas range « 1BxY_…Kp3 » dans la bibliothèque d'un
 * client, où plus personne ne le retrouve. Le vrai déposeur est monté ici pour
 * cette raison - un double aurait rendu le nom qu'on lui aurait appris.
 *
 * C'est aussi ce qui rend le fichier attachable partout : une fois dans la
 * médiathèque, il n'est plus un cas particulier pour les fiches, les notes ni
 * les galeries.
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
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        // Le navigateur pose cet en-tête sur chaque appel, et les routes
        // publiques l'exigent : ce qui les protège est un secret dans
        // l'adresse, et une adresse se transfère.
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

        // Un PNG d'un pixel : le déposeur refuse ce qui n'est pas un fichier
        // qu'il sait traiter, et un contenu inventé ne prouverait rien.
        $pixel = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        $this->givenDrive([
            $this->token(),
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
     * Un document Google n'a pas d'octets à télécharger.
     *
     * Refusé avec un message plutôt qu'avec un document vide : c'est le seul
     * cas où l'écran doit dire quelque chose, et le confondre avec une panne
     * enverrait chercher la cause dans les réglages.
     */
    public function testAFileGoogleWillNotServeIsRefusedAndFilesNothing(): void
    {
        $space = $this->givenSpaceWithDrive();

        $this->givenDrive([
            $this->token(),
            new MockResponse((string) json_encode(['name' => 'Compte rendu', 'mimeType' => 'application/vnd.google-apps.document']), [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
            new MockResponse('', ['http_code' => 403]),
        ]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/fichier-1/import', $space->getId()));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->entityManager->getRepository(Document::class)->findAll());
    }

    /** Sans dossier branché, la route n'existe pas pour cet espace. */
    public function testAnImportIsNotFoundOnASpaceWithNoFolder(): void
    {
        $space = $this->givenSpaceWithDrive(folder: null);

        $this->givenDrive([$this->token()]);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/drive/fichier-1/import', $space->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
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
        // Sans cela, le noyau redémarre à la requête suivante et le service
        // posé ici disparaît avec lui.
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

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
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
