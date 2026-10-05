<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Ged\Search\GedSuiteSearchProvider;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function random_bytes;

/**
 * The media library in the global search.
 *
 * The aggregator behind the search box only asks for `general.search.view`, so
 * every provider has to say for itself who may read what it returns. This one
 * did not, for as long as it existed: an account with the search box and no
 * privilege on the library got file names back, and so did every account on a
 * deployment where the module was switched off.
 *
 * File names are a modest leak, and that is not the point - the point is that
 * three providers answering the same question have to answer it the same way,
 * or nobody can reason about what the search box exposes.
 */
final class GedSearchTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private GedSuiteSearchProvider $provider;

    private SettingRepository $settings;

    /** @var list<object> */
    private array $created = [];

    private string $needle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->provider = $container->get(GedSuiteSearchProvider::class);
        $this->settings = $container->get(SettingRepository::class);

        $this->needle = 'licorne'.bin2hex(random_bytes(4));

        $document = new Document();
        $document
            ->setTitle($this->needle)
            ->setStatus(DocumentStatusEnum::Published)
            ->setFileName($this->needle.'.png')
            ->setOriginalName($this->needle.'.png')
            ->setMimeType('image/png');

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->created[] = $document;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->created = [];

        $this->settings->set(ModuleParameterEnum::GedDocuments->value, '1');

        parent::tearDown();
    }

    public function testAnAccountWithThePrivilegeFindsTheDocument(): void
    {
        $this->client->loginUser($this->accountWith(['ged.documents.view']), 'admin');

        self::assertCount(1, $this->provider->search($this->needle)['media'] ?? []);
    }

    /** The leak: the search box is one privilege, the library is another. */
    public function testAnAccountWithoutThePrivilegeFindsNothing(): void
    {
        $this->client->loginUser($this->accountWith(['general.search.view']), 'admin');

        self::assertSame([], $this->provider->search($this->needle)['media'] ?? []);
    }

    /** A module switched off has no business answering a search. */
    public function testTheModuleSwitchedOffAnswersNothing(): void
    {
        $this->client->loginUser($this->accountWith(['ged.documents.view']), 'admin');
        $this->settings->set(ModuleParameterEnum::GedDocuments->value, '0');

        self::assertSame([], $this->provider->search($this->needle)['media'] ?? []);
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('recherche-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Compte de test')
            ->setType(UserTypeEnum::Suite)
            ->setPassword('irrelevant')
            ->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->created[] = $user;

        return $user;
    }
}
