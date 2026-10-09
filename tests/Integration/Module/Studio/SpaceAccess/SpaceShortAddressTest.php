<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\SpaceAccess;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function json_decode;
use function json_encode;

/**
 * The short address of a client space (10/10/2026): readable, unguessable,
 * unique, and only as alive as the link and the short address themselves.
 */
final class SpaceShortAddressTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkManagerInterface $links;

    private UrlGeneratorInterface $urlGenerator;

    private CustomerSpace $space;

    private Customer $customer;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(SpaceAccessLinkManagerInterface::class);
        $this->urlGenerator = $container->get(UrlGeneratorInterface::class);
        $this->customer = new Customer();
        $this->customer->setLegalName('Boulangerie Fournier')->setSiret(null)->setContractualEmail('fournier@example.test');
        $this->entityManager->persist($this->customer);
        $this->space = new CustomerSpace();
        $this->space->setName('Boulangerie Fournier')->setCustomer($this->customer);
        $this->entityManager->persist($this->space);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();
        foreach ($this->created as $id) {
            $link = $this->entityManager->find(SpaceAccessLink::class, $id);
            if (null !== $link) {
                $this->entityManager->remove($link);
            }
        }
        foreach ([CustomerSpace::class => $this->space->getId(), Customer::class => $this->customer->getId()] as $class => $id) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheShortAddressIsReadableUnguessableAndOpensTheSpace(): void
    {
        $link = $this->link();
        $alias = $this->links->giveAlias($link, 'Boulangerie Fournier');

        self::assertMatchesRegularExpression('/^boulangerie-fournier-[a-hjkmnp-z2-9]{6}$/', $alias);

        $this->client->request('GET', '/c/'.$alias);
        self::assertResponseRedirects();
        $target = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/spaces/'.$link->getSelector().'/', $target);

        $this->client->request('GET', $target);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->links->resolveUsable($link->getSelector(), (string) $this->links->aliasToken($link)));
    }

    public function testTheSameNameNeverGivesTheSameAddress(): void
    {
        $first = $this->links->giveAlias($this->link(), 'Fournier');
        $second = $this->links->giveAlias($this->link(), 'Fournier');

        self::assertNotSame($first, $second);
    }

    public function testANewAddressRetiresTheOldOneAndItsToken(): void
    {
        $link = $this->link();
        $old = $this->links->giveAlias($link, 'Fournier');
        $oldToken = (string) $this->links->aliasToken($link);
        $this->links->giveAlias($link, 'Fournier');

        $this->client->request('GET', '/c/'.$old);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->links->resolveUsable($link->getSelector(), $oldToken));
    }

    public function testRemovingOrRevokingClosesTheShortAddress(): void
    {
        $removed = $this->link();
        $removedAlias = $this->links->giveAlias($removed, 'Retire');
        $this->links->removeAlias($removed);

        $revoked = $this->link();
        $revokedAlias = $this->links->giveAlias($revoked, 'Revoque');
        $this->links->revoke($revoked);

        foreach ([$removedAlias, $revokedAlias, 'inconnu-abcdef'] as $alias) {
            $this->client->request('GET', '/c/'.$alias);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testTheSuiteGivesAndShowsTheShortAddress(): void
    {
        $owner = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $owner);
        $this->client->loginUser($owner, 'admin');
        $link = $this->link();

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('workspace_space_access_alias', ['id' => $this->space->getId(), 'linkId' => $link->getId()]),
            server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['name' => 'Atelier Dupont']),
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $row = array_values(array_filter($body['links'], static fn (array $one): bool => $one['id'] === $link->getId()))[0];

        self::assertMatchesRegularExpression('#/c/atelier-dupont-[a-z2-9]{6}$#', (string) $row['shortUrl']);
    }

    private function link(): SpaceAccessLinkInterface
    {
        $link = $this->links->issue($this->space, 'client@example.com', null, 30, true, true);
        $this->created[] = (int) $link->getId();

        return $link;
    }
}
