<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\SpaceResource;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * Ce qu'un élément fermé ne laisse pas passer.
 *
 * **La fonctionnalité est la case, pas la liste.** Ranger au même endroit ce
 * qui se partage et ce qui ne se partage pas ne vaut que si la case décide
 * vraiment ; une régression ici publierait chez le client un accès qu'on
 * gardait pour soi, et ne se verrait qu'en ouvrant sa page - ce que personne
 * ne fait à chaque déploiement.
 *
 * La garantie est vérifiée dans les deux sens, et dans le HTML servi plutôt
 * que dans un tableau intermédiaire : c'est ce que le client reçoit.
 */
final class SpaceResourceVisibilityTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

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
        foreach ([SpaceResource::class, SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testAnElementIsInvisibleUntilItIsOpenedAndVisibleOnceItIs(): void
    {
        $space = $this->givenSpace();

        // Des libellés sans accent, délibérément : les propriétés voyagent
        // dans un attribut JSON, où un « è » s'écrit `\u00e8`. Une recherche
        // de sous-chaîne accentuée échouerait sur une page correcte, et une
        // recherche « absente » réussirait sur une page qui fuit.
        $this->givenResource($space, 'link', 'Maquette partagee', visible: true);
        $hiddenId = $this->givenResource($space, 'link', 'Acces hebergeur', visible: false);

        $link = $this->givenLink($space);

        $page = $this->clientPage($link);
        self::assertStringContainsString('Maquette partagee', $page);
        self::assertStringNotContainsString('Acces hebergeur', $page);

        // Le même lien, relu après l'ouverture : ce que voit le client change
        // parce que la case a changé, et non parce qu'on lui a donné une
        // autre adresse.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/%d/visibility', $space->getId(), $hiddenId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertStringContainsString('Acces hebergeur', $this->clientPage($link));
    }

    /**
     * L'adresse d'un élément fermé ne voyage pas non plus.
     *
     * Le libellé absent de la page ne prouve que la moitié : un lien dont le
     * nom serait retiré mais l'adresse servie serait toujours un lien donné.
     */
    public function testTheAddressOfAClosedElementNeverReachesThePage(): void
    {
        $space = $this->givenSpace();

        $this->givenResource($space, 'link', 'Acces hebergeur', visible: false, url: 'https://panel.example.com/secret-du-studio');

        self::assertStringNotContainsString('secret-du-studio', $this->clientPage($this->givenLink($space)));
    }

    /**
     * Le drapeau lui-même ne part pas.
     *
     * Tout ce qui arrive sur la page du client est ouvert, donc `visibleToClient`
     * ne pourrait dire que « oui » : un drapeau à une seule valeur n'informe
     * personne et fait croire qu'il en a deux.
     */
    public function testTheFlagItselfDoesNotTravel(): void
    {
        $space = $this->givenSpace();
        $this->givenResource($space, 'text', 'Ton et vocabulaire', visible: true, body: 'Phrases courtes.');

        self::assertStringNotContainsString('visibleToClient', $this->clientPage($this->givenLink($space)));
    }

    /**
     * Une ressource d'un autre espace, désignée sous celui-ci, n'existe pas.
     *
     * Elle arrive par l'URL comme sa propre entité, donc rien n'empêche une
     * requête fabriquée de nommer la ressource d'un client sous l'espace d'un
     * autre. C'est la seule chose qui sépare deux listes.
     */
    public function testAResourceOfAnotherSpaceIsNotFoundUnderThisOne(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace('Espace du voisin', 'voisin@example.test');

        $foreign = $this->givenResource($theirs, 'text', "Ce qui n'est pas à moi", visible: false, body: 'Rien.');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/%d/delete', $mine->getId(), $foreign));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** Le genre décide de ce qui est exigé, et l'écran le dit champ par champ. */
    public function testALinkWithoutAnAddressIsRefusedOnItsOwnField(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/create', $space->getId()), [
            'kind' => 'link',
            'label' => 'Une maquette',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('url', $payload['errors']);
    }

    /** Un texte, lui, veut un corps - et pas d'adresse. */
    public function testATextWithoutABodyIsRefusedOnItsOwnField(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/create', $space->getId()), [
            'kind' => 'text',
            'label' => 'Ton et vocabulaire',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('body', $payload['errors']);
    }

    /**
     * Une adresse `javascript:` n'est jamais enregistrée.
     *
     * Le champ finit dans un `href` sur la page d'un client, donc la question
     * n'est pas esthétique : ce qui n'est pas refusé ici est cliquable là-bas.
     */
    public function testAnAddressThatIsNotWebIsRefused(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/create', $space->getId()), [
            'kind' => 'link',
            'label' => 'Piège',
            'url' => 'javascript:alert(1)',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
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

    private function givenResource(
        CustomerSpace $space,
        string $kind,
        string $label,
        bool $visible,
        ?string $url = null,
        ?string $body = null,
    ): int {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/create', $space->getId()), [
            'kind' => $kind,
            'label' => $label,
            'url' => $url ?? ('link' === $kind ? 'https://exemple.example.com/une-page' : null),
            'body' => $body,
            'visibleToClient' => $visible,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        foreach ($payload['resources'] as $row) {
            if ($row['label'] === $label) {
                return (int) $row['id'];
            }
        }

        self::fail(sprintf('La ressource « %s » n\'est pas revenue dans la liste.', $label));
    }

    private function givenSpace(string $name = 'Espace des ressources', string $email = 'ressources@example.test'): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client '.$name)->setContractualEmail($email);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

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
