<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Preview\ContractTemplatePreviewer;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Search\StudioBackendSearchProvider;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\StudioContext;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function array_keys;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

/**
 * Studio in the global search.
 *
 * Six sections behind four switches and five privileges, so most of this is
 * about each section asking its own screen's question - and about the spaces:
 * a teammate finds the spaces they are on and the cards in them, never a
 * client's board they were not put on.
 */
final class StudioSearchTest extends IntegrationTestCase
{
    private const array SECTIONS = ['spaces', 'space_contents', 'customers', 'contracts', 'contract_templates', 'decks'];

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private StudioBackendSearchProvider $provider;

    private SettingRepository $settings;

    private User $admin;

    private string $needle;

    /** @var list<object> in creation order, removed in reverse */
    private array $created = [];

    /** @var list<int> */
    private array $templateIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->provider = $container->get(StudioBackendSearchProvider::class);
        $this->settings = $container->get(SettingRepository::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;

        $this->needle = 'licorne'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        foreach ($this->templateIds as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s t WHERE t.id = :id', ContractTemplate::class))->setParameter('id', $id)->execute();
        }

        $this->templateIds = [];

        foreach ([ModuleParameterEnum::StudioBackend, ModuleParameterEnum::StudioSpaces, ModuleParameterEnum::StudioCustomers, ModuleParameterEnum::StudioContracts, ModuleParameterEnum::StudioDecks] as $toggle) {
            $this->settings->set($toggle->value, '1');
        }

        $this->forgetSwitches();

        parent::tearDown();
    }

    /** An administrator sees every space, and every section answers with a path. */
    public function testEachSectionFindsItsRowAndCarriesThePathThatOpensIt(): void
    {
        $customer = $this->customer('Boulangerie '.$this->needle, '73282932000074');
        $space = $this->space('Lancement '.$this->needle, $customer);
        $item = $this->item($space, 'Carrousel '.$this->needle);
        $contract = $this->contract($customer, 'CT-'.$this->needle);
        $template = $this->template('Trame '.$this->needle);
        $deck = $this->deck('Atelier '.$this->needle);

        $this->client->loginUser($this->admin, 'admin');
        $results = $this->provider->search($this->needle);

        self::assertSame(self::SECTIONS, array_keys($results));

        self::assertSame(['Lancement '.$this->needle], array_column($results['spaces'], 'title'));
        self::assertSame(sprintf('/workspace/%d', $space->getId()), $results['spaces'][0]['path']);
        self::assertSame('Boulangerie '.$this->needle, $results['spaces'][0]['subtitle']);

        self::assertSame(['Carrousel '.$this->needle], array_column($results['space_contents'], 'title'));
        self::assertSame(sprintf('/workspace/%d?item=%d', $space->getId(), $item->getId()), $results['space_contents'][0]['path']);

        self::assertSame(['Boulangerie '.$this->needle], array_column($results['customers'], 'title'));
        self::assertStringStartsWith('/backend/studio/customers?search=', $results['customers'][0]['path']);

        self::assertSame(['CT-'.$this->needle], array_column($results['contracts'], 'title'));
        self::assertSame(sprintf('/backend/studio/contracts/%d', $contract->getId()), $results['contracts'][0]['path']);

        self::assertSame(['Trame '.$this->needle], array_column($results['contract_templates'], 'title'));
        self::assertSame(
            sprintf('/backend/studio/contract-templates/%d/versions/%d', $template->getId(), $template->getDraft()?->getId()),
            $results['contract_templates'][0]['path'],
        );

        self::assertSame(['Atelier '.$this->needle], array_column($results['decks'], 'title'));
        self::assertSame(sprintf('/backend/studio/decks/%d', $deck->getId()), $results['decks'][0]['path']);
    }

    /** Through the search box's own endpoint, which merges every provider. */
    public function testTheSearchBoxEndpointReturnsTheStudioSections(): void
    {
        $deck = $this->deck('Atelier '.$this->needle);

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', '/backend/general/search?q='.$this->needle, server: self::FROM_THE_PAGE);
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(sprintf('/backend/studio/decks/%d', $deck->getId()), $payload['decks'][0]['path'] ?? null);
    }

    /** A space is found by its client's name too, and a customer by its number written in groups. */
    public function testASpaceIsFoundByItsCustomerAndACustomerByItsSiret(): void
    {
        $customer = $this->customer('Fromagerie '.$this->needle, '39860733100024');
        $this->space('Site vitrine', $customer);

        $this->client->loginUser($this->admin, 'admin');

        self::assertSame(['Site vitrine'], array_column($this->provider->search('fromagerie '.$this->needle)['spaces'], 'title'));
        self::assertContains('Fromagerie '.$this->needle, array_column($this->provider->search('398 607 331 00024')['customers'], 'title'));
    }

    /**
     * The membership rule: a teammate finds the spaces they are on and the
     * cards in them, and nothing of a space they were not put on.
     */
    public function testATeammateNeverFindsASpaceTheyAreNotOnNorItsCards(): void
    {
        $customer = $this->customer('Client '.$this->needle, null);
        $mine = $this->space('Le mien '.$this->needle, $customer);
        $theirs = $this->space('Le leur '.$this->needle, $customer);
        $this->item($mine, 'Carte du mien '.$this->needle);
        $this->item($theirs, 'Carte du leur '.$this->needle);

        $teammate = $this->accountWith(['studio.spaces.view']);
        $this->membership($mine, $teammate);
        $this->client->loginUser($teammate, 'admin');

        $results = $this->provider->search($this->needle);

        self::assertSame(['spaces', 'space_contents'], array_keys($results), 'only the sections this account may open');
        self::assertSame(['Le mien '.$this->needle], array_column($results['spaces'], 'title'));
        self::assertSame(['Carte du mien '.$this->needle], array_column($results['space_contents'], 'title'));
    }

    public function testATeammateOnNoSpaceFindsNoSpace(): void
    {
        $customer = $this->customer('Client '.$this->needle, null);
        $space = $this->space('Espace '.$this->needle, $customer);
        $this->item($space, 'Carte '.$this->needle);

        $this->client->loginUser($this->accountWith(['studio.spaces.view']), 'admin');
        $results = $this->provider->search($this->needle);

        self::assertSame([], $results['spaces']);
        self::assertSame([], $results['space_contents']);
    }

    /** The leak: the search box is one privilege, each Studio screen is another. */
    public function testAnAccountWithoutAnyStudioPrivilegeFindsNothing(): void
    {
        $this->customer('Boulangerie '.$this->needle, null);
        $this->deck('Atelier '.$this->needle);

        $this->client->loginUser($this->accountWith(['general.search.view']), 'admin');

        self::assertSame([], $this->provider->search($this->needle));
    }

    public function testEachSectionAnswersToItsOwnPrivilege(): void
    {
        $this->client->loginUser($this->accountWith(['studio.decks.view', 'studio.contract_templates.view']), 'admin');

        self::assertSame(['contract_templates', 'decks'], array_keys($this->provider->search($this->needle)));
    }

    /** A section switched off has no business answering, and neither has the module. */
    public function testASwitchedOffSectionAnswersNothing(): void
    {
        $this->client->loginUser($this->admin, 'admin');

        $this->settings->set(ModuleParameterEnum::StudioSpaces->value, '0');
        $this->settings->set(ModuleParameterEnum::StudioContracts->value, '0');
        $this->forgetSwitches();
        self::assertSame(['customers', 'decks'], array_keys($this->provider->search($this->needle)));

        $this->settings->set(ModuleParameterEnum::StudioBackend->value, '0');
        $this->forgetSwitches();
        self::assertSame([], $this->provider->search($this->needle));
    }

    /** `%` and `_` are searched for, not used as wildcards. */
    public function testLikeWildcardsAreTakenLiterally(): void
    {
        $this->customer('Remise 100% '.$this->needle, null);

        $this->client->loginUser($this->admin, 'admin');

        self::assertSame(['Remise 100% '.$this->needle], array_column($this->provider->search('100% '.$this->needle)['customers'], 'title'));

        foreach ($this->provider->search('%')['customers'] as $row) {
            self::assertStringContainsString('%', $row['title']);
        }
    }

    public function testWithNobodySignedInItReturnsNothing(): void
    {
        self::assertSame([], $this->provider->search($this->needle));
    }

    /** One section failing takes that section out, not the search box, nor the other sections. */
    public function testAnInternalFailureDoesNotThrowAndSparesTheOtherSections(): void
    {
        $this->deck('Atelier '.$this->needle);
        $this->client->loginUser($this->admin, 'admin');

        $customers = $this->createStub(CustomerRepository::class);
        $customers->method('searchByNameOrNumber')->willThrowException(new RuntimeException('Base indisponible'));

        $container = self::getContainer();
        $provider = new StudioBackendSearchProvider(
            $container->get(StudioContext::class),
            $container->get(Security::class),
            $container->get(SpaceVisibility::class),
            $container->get(CustomerSpaceRepository::class),
            $container->get(SpaceContentItemRepository::class),
            $customers,
            $container->get(ContractRepository::class),
            $container->get(ContractTemplateRepository::class),
            $container->get(DeckRepository::class),
            $container->get(UrlGeneratorInterface::class),
            $container->get(TranslatorInterface::class),
        );

        $results = $provider->search($this->needle);

        self::assertSame([], $results['customers']);
        self::assertSame(['Atelier '.$this->needle], array_column($results['decks'], 'title'));
    }

    /** The checker keeps what it read for the request; a test flipping switches starts a new one. */
    private function forgetSwitches(): void
    {
        self::getContainer()->get(ModuleAccessChecker::class)->reset();
    }

    private function customer(string $name, ?string $siret): Customer
    {
        $customer = new Customer();
        $customer->setLegalName($name)->setSiret($siret)->setContractualEmail('recherche@example.test');
        $this->persist($customer);

        return $customer;
    }

    private function space(string $name, Customer $customer): CustomerSpace
    {
        $space = new CustomerSpace();
        $space->setName($name)->setCustomer($customer);
        $this->persist($space);

        return $space;
    }

    private function item(CustomerSpace $space, string $title): SpaceContentItem
    {
        $column = new SpaceContentColumn();
        $column->setSpace($space)->setName('À faire');
        $this->persist($column);

        $item = new SpaceContentItem();
        $item->setSpace($space)->setColumn($column)->setTitle($title);
        $this->persist($item);

        return $item;
    }

    private function contract(Customer $customer, string $reference): Contract
    {
        $contract = new Contract();
        $contract->setCustomer($customer)->setReference($reference);
        $this->persist($contract);

        return $contract;
    }

    private function template(string $name): ContractTemplateInterface
    {
        $container = self::getContainer();
        $manager = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
            $container->get(ContractTemplatePreviewer::class),
        );

        $template = $manager->create(new ContractTemplateInput($name, ContractTemplateKindEnum::Body));
        $this->templateIds[] = (int) $template->getId();

        return $template;
    }

    private function deck(string $title): Deck
    {
        $deck = self::getContainer()->get(DeckManager::class)->create($title);
        self::assertInstanceOf(Deck::class, $deck);
        $this->entityManager->flush();
        $this->created[] = $deck;

        return $deck;
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('recherche-studio-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Équipier')
            ->setType(UserTypeEnum::Backend)
            ->setRoles([UserRoleEnum::User->value])
            ->setPassword('irrelevant')
            ->setPrivileges($privileges);
        $this->persist($user);

        return $user;
    }

    private function membership(CustomerSpace $space, User $user): void
    {
        $member = new CustomerSpaceMember();
        $member->setSpace($space)->setUser($user)->setRole(CustomerSpaceMemberRoleEnum::Member);
        $space->getMembers()->add($member);
        $this->persist($member);
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
