<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Customer;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

/**
 * La fiche d'un client, remplie depuis son espace.
 *
 * Trois garanties, et aucune ne se voit sur l'écran qui les produit :
 *
 * **Elle appartient au client.** Deux espaces ouverts pour la même société
 * montrent la même fiche. Une copie par espace se serait contredite dès le
 * deuxième projet, et personne ne l'aurait su avant de comparer.
 *
 * **Elle ne touche pas à l'identité contractuelle.** L'écran ne montre ni le
 * capital, ni le RCS, ni la TVA, ni le représentant ; les lui faire porter
 * aurait effacé tout cela au premier enregistrement depuis un projet, et le
 * contrat suivant serait parti incomplet.
 *
 * **Les deux numéros doivent s'accorder.** Un SIRET commence par son SIREN ;
 * deux numéros qui se contredisent sur la même ligne donnent une fiche qui
 * porte deux identités.
 */
final class SpaceInformationTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkManagerInterface $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(SpaceAccessLinkManagerInterface::class);

        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
    }

    protected function tearDown(): void
    {
        foreach ([SpaceResource::class, SpaceAccessLink::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheContractualIdentitySurvivesASaveFromASpace(): void
    {
        $customer = $this->givenCustomer();
        $space = $this->givenSpace($customer, 'Premier projet');

        $this->save($space, [
            'legalName' => 'Atelier Temoin',
            'siret' => '11281704400004',
            'siren' => '112817044',
            'phone' => '06 11 22 33 44',
            'postalAddress' => '1 rue Neuve, 38000 Grenoble',
        ]);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Customer::class)->find($customer->getId());
        self::assertInstanceOf(Customer::class, $reloaded);

        // Ce que l'écran a écrit.
        self::assertSame('112817044', $reloaded->getSiren());
        self::assertSame('06 11 22 33 44', $reloaded->getPhone());

        // Ce qu'il n'a pas touché, et qui n'était sur aucun de ses champs.
        self::assertSame(1_000_000, $reloaded->getShareCapitalCents());
        self::assertSame(CurrencyEnum::EUR, $reloaded->getShareCapitalCurrency());
        self::assertSame('Lyon B 123 456 789', $reloaded->getTradeRegister());
        self::assertSame('FR12345678901', $reloaded->getVatNumber());
        self::assertSame('Gerante', $reloaded->getRepresentativeRole());
    }

    public function testTwoSpacesOfTheSameCustomerShowTheSameSheet(): void
    {
        $customer = $this->givenCustomer();
        $first = $this->givenSpace($customer, 'Premier projet');
        $second = $this->givenSpace($customer, 'Second projet');

        $this->save($first, ['legalName' => 'Atelier Temoin', 'phone' => '06 55 44 33 22']);

        $payload = $this->save($second, ['legalName' => 'Atelier Temoin', 'phone' => '06 55 44 33 22']);
        self::assertSame('06 55 44 33 22', $payload['information']['phone']);
    }

    /**
     * Un SIREN qui ne correspond pas au SIRET est refusé, sur son champ.
     *
     * L'erreur se pose sur le SIREN et non sur le SIRET : c'est le champ qu'on
     * vient d'ajouter à l'écran, donc celui qu'on vient de taper.
     */
    public function testTwoNumbersThatDisagreeAreRefusedOnTheSirenField(): void
    {
        $space = $this->givenSpace($this->givenCustomer(), 'Premier projet');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/information/save', $space->getId()), [
            'legalName' => 'Atelier Temoin',
            'siret' => '11281704400004',
            'siren' => '732456306',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('siren', $payload['errors']);
        self::assertArrayNotHasKey('siret', $payload['errors']);
    }

    /** Une clé de contrôle fausse est un numéro faux, pas un numéro court. */
    public function testASirenWithABadCheckDigitIsRefused(): void
    {
        $space = $this->givenSpace($this->givenCustomer(), 'Premier projet');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/information/save', $space->getId()), [
            'legalName' => 'Atelier Temoin',
            'siren' => '112817040',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Une ligne de liens ouverte puis laissée vide n'empêche pas d'enregistrer.
     *
     * Le bouton « ajouter » en pose une vide ; refuser à cause d'elle
     * obligerait à la retirer avant de sauver, ce que personne ne comprend.
     * Une ligne à moitié remplie, elle, est une vraie erreur.
     */
    public function testAnEmptyLinkRowIsDroppedAndAHalfFilledOneIsReported(): void
    {
        $space = $this->givenSpace($this->givenCustomer(), 'Premier projet');

        $payload = $this->save($space, [
            'legalName' => 'Atelier Temoin',
            'links' => [
                ['label' => 'Site web', 'url' => 'https://atelier.example.com'],
                ['label' => '', 'url' => ''],
            ],
        ]);

        self::assertCount(1, $payload['information']['links']);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/information/save', $space->getId()), [
            'legalName' => 'Atelier Temoin',
            'links' => [['label' => 'Site web', 'url' => '']],
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $errors = json_decode((string) $this->client->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('links[0].url', $errors);
    }

    /**
     * L'onglet du client n'existe pas tant que la fiche ne dit rien.
     *
     * Une société connaît son propre nom : un onglet qui ne lui apprendrait
     * que celui-là est un onglet qu'on ouvre une fois.
     */
    public function testTheClientTabAppearsOnlyOnceTheSheetSaysSomething(): void
    {
        $customer = new Customer();
        $customer->setLegalName('Societe Sans Fiche')->setContractualEmail(null);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $space = $this->givenSpace($customer, 'Projet nu');
        $link = $this->givenLink($space);

        // Les propriétés voyagent dans un attribut, donc les guillemets y sont
        // échappés : c'est bien ce HTML-là que le client reçoit.
        self::assertStringContainsString('information&quot;:null', $this->clientPage($link));

        $this->save($space, ['legalName' => 'Societe Sans Fiche', 'phone' => '06 00 11 22 33']);

        self::assertStringContainsString('06 00 11 22 33', $this->clientPage($link));
    }

    /**
     * Tenir le tableau d'un espace n'autorise pas à changer la société.
     *
     * **La porte d'à côté n'est pas une autorisation.** L'écran s'ouvre depuis
     * un projet, mais ce qu'il modifie est l'identité d'un client, et celle-ci
     * apparaît sur ses contrats. Quelqu'un à qui on a confié la production
     * d'un espace, et à qui on n'a pas confié les fiches clients, ne doit pas
     * y arriver par ce chemin.
     */
    public function testHoldingASpaceDoesNotOpenTheCustomerSheet(): void
    {
        $space = $this->givenSpace($this->givenCustomer(), 'Premier projet');

        // Tout ce qu'il faut pour travailler dans l'espace, et rien sur les
        // clients : c'est exactement le compte que la garde vise.
        // Membre de l'espace, sans quoi l'espace lui-même n'existerait pas
        // pour lui et le refus serait un 404 dont on n'apprendrait rien.
        $account = $this->account(['studio.spaces.view', 'studio.spaces.edit']);
        $this->joinSpace($space, $account);
        $this->client->loginUser($account, 'admin');

        // **La moitié positive d'abord.** Un 403 tout seul ne prouve rien : il
        // serait le même si le compte n'était pas connecté, ou si la route
        // n'existait pas. Ce compte travaille réellement dans l'espace.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/create', $space->getId()), [
            'kind' => 'text',
            'label' => 'Une note de production',
            'body' => 'Ecrite par quelqu un qui tient cet espace.',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/information/save', $space->getId()), [
            'legalName' => 'Renomme par quelqu un d autre',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Et la fiche n'a pas bougé.
        $this->entityManager->clear();
        $customers = $this->entityManager->getRepository(Customer::class)->findAll();
        self::assertSame('Atelier Temoin', $customers[0]->getLegalName());
    }

    /** Ce qui n'est pas web n'est pas une adresse qu'on enregistre. */
    public function testALinkThatIsNotWebIsRefused(): void
    {
        $space = $this->givenSpace($this->givenCustomer(), 'Premier projet');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/information/save', $space->getId()), [
            'legalName' => 'Atelier Temoin',
            'links' => [['label' => 'Piege', 'url' => 'javascript:alert(1)']],
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** @param array<string, mixed> $payload */
    private function save(CustomerSpace $space, array $payload): array
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/information/save', $space->getId()), $payload);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function clientPage(SpaceAccessLinkInterface $link): string
    {
        $this->client->request('GET', sprintf('/spaces/%s/%s', $link->getSelector(), (string) $link->getPlainToken()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }

    private function givenLink(CustomerSpace $space): SpaceAccessLinkInterface
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return $this->links->issue($fresh, 'client@example.test', 'Le client', 30, true, true);
    }

    private function joinSpace(CustomerSpace $space, User $user): void
    {
        $member = new CustomerSpaceMember();
        $member->setSpace($space)->setUser($user)->setRole(CustomerSpaceMemberRoleEnum::Member);

        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    /**
     * Un compte de production, avec ces privilèges-là et pas d'autres.
     *
     * @param list<string> $privileges
     */
    private function account(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('espace-'.bin2hex(random_bytes(4)).'@aurora.test')
            ->setName('Production')
            ->setType(UserTypeEnum::Backend)
            ->setPassword('x')
            ->setRoles(['ROLE_USER'])
            ->setPrivileges($privileges);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /** Une société dont l'identité contractuelle est complète, comme après un contrat. */
    private function givenCustomer(): Customer
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Atelier Temoin')
            ->setContractualEmail('temoin@example.test')
            ->setShareCapitalCents(1_000_000)
            ->setShareCapitalCurrency(CurrencyEnum::EUR)
            ->setTradeRegister('Lyon B 123 456 789')
            ->setVatNumber('FR12345678901')
            ->setRepresentativeFirstName('Marie')
            ->setRepresentativeLastName('Dupont')
            ->setRepresentativeRole('Gerante');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function givenSpace(Customer $customer, string $name): CustomerSpace
    {
        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => $name,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
