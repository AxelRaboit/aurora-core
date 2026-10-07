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
 * What a closed item does not let through.
 *
 * **The feature is the checkbox, not the list.** Keeping what is shared and
 * what is not in the same place is only worth it if the checkbox really
 * decides; a regression here would publish to the client an access that was
 * kept private, and would only show by opening their page - which nobody
 * does on every deploy.
 *
 * The guarantee is checked both ways, and in the served HTML rather than in
 * an intermediate array: that is what the client receives.
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
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
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

        // Labels without accents, on purpose: the props travel in a JSON
        // attribute, where an "è" is written `\u00e8`. A search for an
        // accented substring would fail on a correct page, and an "absent"
        // search would succeed on a page that leaks.
        $this->givenResource($space, 'link', 'Maquette partagee', visible: true);
        $hiddenId = $this->givenResource($space, 'link', 'Acces hebergeur', visible: false);

        $link = $this->givenLink($space);

        $page = $this->clientPage($link);
        self::assertStringContainsString('Maquette partagee', $page);
        self::assertStringNotContainsString('Acces hebergeur', $page);

        // The same link, read again after opening: what the client sees
        // changes because the checkbox changed, and not because they were
        // given another address.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/%d/visibility', $space->getId(), $hiddenId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertStringContainsString('Acces hebergeur', $this->clientPage($link));
    }

    /**
     * A closed item's address does not travel either.
     *
     * The label missing from the page only proves half of it: a link whose
     * name was removed but whose address was still served would still be a
     * link given away.
     */
    public function testTheAddressOfAClosedElementNeverReachesThePage(): void
    {
        $space = $this->givenSpace();

        $this->givenResource($space, 'link', 'Acces hebergeur', visible: false, url: 'https://panel.example.com/secret-du-studio');

        self::assertStringNotContainsString('secret-du-studio', $this->clientPage($this->givenLink($space)));
    }

    /**
     * The flag itself is not sent.
     *
     * Everything that reaches the client's page is open, so `visibleToClient`
     * could only say "yes": a flag with a single value informs nobody and
     * suggests it has two.
     */
    public function testTheFlagItselfDoesNotTravel(): void
    {
        $space = $this->givenSpace();
        $this->givenResource($space, 'text', 'Ton et vocabulaire', visible: true, body: 'Phrases courtes.');

        self::assertStringNotContainsString('visibleToClient', $this->clientPage($this->givenLink($space)));
    }

    /**
     * A resource of another space, named under this one, does not exist.
     *
     * It arrives through the URL as its own entity, so nothing stops a crafted
     * request from naming one client's resource under another client's space.
     * This is the only thing that separates two lists.
     */
    public function testAResourceOfAnotherSpaceIsNotFoundUnderThisOne(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace('Espace du voisin', 'voisin@example.test');

        $foreign = $this->givenResource($theirs, 'text', "Ce qui n'est pas à moi", visible: false, body: 'Rien.');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/resources/%d/delete', $mine->getId(), $foreign));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** The kind decides what is required, and the screen says so field by field. */
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

    /** A text, on the other hand, needs a body - and no address. */
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
     * A `javascript:` address is never saved.
     *
     * The field ends up in an `href` on a client's page, so the question is
     * not cosmetic: what is not refused here is clickable there.
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

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
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
