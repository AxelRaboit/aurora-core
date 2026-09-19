<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\SpaceNote\Craft\Service\CraftClient;
use Aurora\Module\Studio\SpaceNote\Craft\Setting\CraftSettingEnum;
use Aurora\Module\Studio\SpaceNote\Craft\Setting\CraftSettings;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function base64_decode;
use function base64_encode;
use function json_decode;
use function sprintf;

/**
 * Un document Craft qui devient une note de l'espace.
 *
 * Le chemin traverse quatre pièces - la connexion, la conversion, le
 * gestionnaire de notes et l'écran - et chacune peut casser sans que les trois
 * autres s'en aperçoivent. Ce qui est vérifié ici est le bout du bout : une
 * note existe, elle porte le titre choisi dans la liste, son corps est fait
 * des blocs que l'éditeur sait ouvrir, et elle se souvient d'où elle vient.
 *
 * **Sans image.** Le dépôt d'une image distante demande un vrai fichier et un
 * vrai stockage ; ce qu'il fait de son Markdown est couvert par les tests de
 * {@see MarkdownToBlocks}, et son échec est conçu pour être sans gravité - le
 * bloc garde son adresse d'origine.
 */
final class SpaceNoteCraftImportTest extends IntegrationTestCase
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
    }

    protected function tearDown(): void
    {
        foreach ([
            SpaceNote::class,
            CustomerSpaceMember::class,
            CustomerSpace::class,
            Customer::class,
        ] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheScreenSaysWhenNoConnectionWasEverOpened(): void
    {
        $space = $this->givenSpace();
        $this->givenCraft(enabled: false, responses: []);

        $this->client->request('GET', sprintf('/workspace/%d/notes/craft', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = $this->payload();

        self::assertFalse($payload['configured']);
        self::assertSame([], $payload['documents']);
    }

    public function testTheListIsWhatTheConnectionLetsThrough(): void
    {
        $space = $this->givenSpace();
        $this->givenCraft(enabled: true, responses: [
            new MockResponse((string) json_encode(['documents' => [
                ['rootBlockId' => 'doc-2', 'title' => 'Brief septembre'],
                ['rootBlockId' => 'doc-1', 'title' => 'Atelier'],
            ]]), ['response_headers' => ['content-type' => 'application/json']]),
        ]);

        $this->client->request('GET', sprintf('/workspace/%d/notes/craft', $space->getId()));

        $payload = $this->payload();

        self::assertTrue($payload['configured']);
        self::assertSame(
            [['id' => 'doc-1', 'title' => 'Atelier'], ['id' => 'doc-2', 'title' => 'Brief septembre']],
            $payload['documents'],
        );
    }

    public function testADocumentBecomesANoteWhoseBodyTheEditorCanOpen(): void
    {
        $space = $this->givenSpace();
        $this->givenCraft(enabled: true, responses: [
            new MockResponse("# Brief septembre\n\nTrois **choses** à faire.\n\n- Relire\n- Envoyer\n"),
        ]);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/notes/craft/import', $space->getId()),
            ['documentId' => 'doc-2', 'title' => 'Brief septembre'],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $notes = $this->entityManager->getRepository(SpaceNote::class)->findAll();

        self::assertCount(1, $notes);

        $note = $notes[0];

        self::assertSame('Brief septembre', $note->getTitle());
        // D'où elle vient, gardé pour le studio seul.
        self::assertSame('doc-2', $note->getCraftDocumentId());

        $body = $note->getBody();

        self::assertSame(['header', 'paragraph', 'list'], array_column($body, 'type'));
        // Le niveau deux, et pas le un du Markdown : l'éditeur ne monte pas
        // les autres, et un bloc hors de sa plage ne s'ouvre pas.
        self::assertSame(2, $body[0]['data']['level']);
        self::assertSame('Trois <b>choses</b> à faire.', $body[1]['data']['text']);
        self::assertCount(2, $body[2]['data']['items']);

        // Le mur revient avec la réponse, pour que l'écran montre la note sans
        // recharger la page.
        self::assertCount(1, $this->payload()['notes']);
    }

    /** Craft muet ne doit pas donner une note vide portant un titre. */
    public function testASilentCraftCreatesNothing(): void
    {
        $space = $this->givenSpace();
        $this->givenCraft(enabled: true, responses: [new MockResponse('', ['http_code' => 404])]);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/notes/craft/import', $space->getId()),
            ['documentId' => 'absent', 'title' => 'Rien'],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->entityManager->getRepository(SpaceNote::class)->findAll());
    }

    public function testAnImportWithoutADocumentIsRefused(): void
    {
        $space = $this->givenSpace();
        $this->givenCraft(enabled: true, responses: []);

        $this->client->jsonRequest(
            'POST',
            sprintf('/workspace/%d/notes/craft/import', $space->getId()),
            ['documentId' => '', 'title' => ''],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->entityManager->getRepository(SpaceNote::class)->findAll());
    }

    /**
     * Un vrai client, sur un vrai transport de test : la conversion et la
     * lecture de la réponse sont donc exercées, seul le réseau est feint.
     *
     * @param list<MockResponse> $responses
     */
    private function givenCraft(bool $enabled, array $responses): void
    {
        $store = [
            CraftSettingEnum::Enabled->value => $enabled ? '1' : '0',
            CraftSettingEnum::Endpoint->value => 'https://connect.example/c/1',
            CraftSettingEnum::Token->value => base64_encode('jeton'),
        ];

        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $store[$key] ?? $default,
        );
        $repository->method('getBoolean')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => '1' === ($store[$key] ?? ($default ? '1' : '0')),
        );

        $encryption = new class implements EncryptionServiceInterface {
            public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }

            public function decrypt(string $encoded): ?string
            {
                $decoded = base64_decode($encoded, strict: true);

                return false === $decoded ? null : $decoded;
            }
        };

        // Sans cela, le noyau redémarre à la requête suivante et le service
        // posé ici disparaît avec lui : c'est le piège classique du client de
        // test, et il se voit comme une connexion « jamais ouverte ».
        $this->client->disableReboot();

        static::getContainer()->set(CraftClient::class, new CraftClient(
            new MockHttpClient($responses),
            new NullLogger(),
            new CraftSettings($repository, $encryption),
        ));
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client de Craft')
            ->setSiret('73282932000074')
            ->setContractualEmail('craft@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace de Craft',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $decoded;
    }
}
