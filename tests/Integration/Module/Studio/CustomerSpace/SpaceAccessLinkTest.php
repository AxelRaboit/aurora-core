<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\Concern\ComparesRefusalPages;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;
use function str_repeat;

/**
 * The address that opens a client space, and everything it refuses.
 *
 * Most of this is about refusals, deliberately: the link is the only door into
 * a client's unpublished work that does not go through a login, so what it
 * declines matters more than what it serves.
 */
final class SpaceAccessLinkTest extends IntegrationTestCase
{
    use ComparesRefusalPages;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkRepository $links;

    private SpaceContentColumnRepository $columns;

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
        $this->links = $container->get(SpaceAccessLinkRepository::class);
        $this->columns = $container->get(SpaceContentColumnRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceAccessLink::class, SpaceContentItem::class, SpaceContentColumn::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheAddressIsHandedBackExactlyOnce(): void
    {
        $space = $this->givenSpace();

        $url = $this->issue($space, 'camille@societe.test')['url'];
        self::assertMatchesRegularExpression('#/spaces/[a-f0-9]{32}/[a-f0-9]{64}$#', $url);

        // The row keeps a hash and nothing else. Reading the list back cannot
        // reconstruct the address, which is the whole point of the design and
        // the reason the screen says "copy it now".
        $link = $this->links->findAll()[0];
        self::assertSame(64, mb_strlen($link->getHashedToken()));
        self::assertStringNotContainsString($link->getHashedToken(), $url);

        $this->client->request('GET', sprintf('/workspace/%d/access', $space->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString($link->getSelector().'/', (string) $this->client->getResponse()->getContent());
    }

    public function testAClientOpensTheSpaceWithNoAccount(): void
    {
        $space = $this->givenSpace();
        // ASCII on purpose: the card travels inside a JSON prop, and
        // `json_encode` writes any accent as \uXXXX in the attribute.
        $this->givenItem($space, 'Portes ouvertes', '2026-11-12T10:00');

        $url = $this->issue($space, 'camille@societe.test')['url'];

        // The same client with its session dropped, which is a guest: only
        // one kernel may boot per test, so a second client is not an option.
        $guest = $this->asGuest();
        $guest->request('GET', $this->pathOf($url));

        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $body = (string) $guest->getResponse()->getContent();
        self::assertStringContainsString('Portes ouvertes', $body);
        // Nothing that writes travels to a page that cannot write.
        self::assertStringNotContainsString('/content/create', $body);
        self::assertStringNotContainsString('/content/reorder', $body);
    }

    public function testTheGuestPageIsKeptOutOfEveryCache(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space, 'camille@societe.test')['url'];

        $guest = $this->asGuest();
        $guest->request('GET', $this->pathOf($url));

        $headers = $guest->getResponse()->headers;
        // A secret address behind a shared proxy would be handed to the next
        // person asking for the same URL.
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
    }

    public function testAWrongSecretIsRefusedLikeAnUnknownOne(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space, 'camille@societe.test')['url'];

        $selector = $this->links->findAll()[0]->getSelector();

        $guest = $this->asGuest();
        $guest->request('GET', sprintf('/spaces/%s/%s', $selector, str_repeat('a', 64)));
        $withWrongSecret = $this->withoutPerRequestNoise((string) $guest->getResponse()->getContent());
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $guest->request('GET', sprintf('/spaces/%s/%s', str_repeat('b', 32), str_repeat('a', 64)));
        $withUnknownSelector = $this->withoutPerRequestNoise((string) $guest->getResponse()->getContent());

        // The same page for both. Telling them apart would tell a stranger
        // which of their guesses landed, and confirm an address was once real.
        self::assertSame($withWrongSecret, $withUnknownSelector);
        self::assertStringNotContainsString($this->pathOf($url), $withWrongSecret);
    }

    public function testARevokedAddressStopsOpening(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space, 'camille@societe.test')['url'];
        $link = $this->links->findAll()[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/%d/revoke', $space->getId(), $link->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $guest = $this->asGuest();
        $guest->request('GET', $this->pathOf($url));

        self::assertSame(200, $guest->getResponse()->getStatusCode());
        self::assertStringContainsString('unavailable', $this->unavailableMarker($guest->getResponse()->getContent()));
    }

    public function testAnExpiredAddressStopsOpening(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space, 'camille@societe.test')['url'];

        $link = $this->links->findAll()[0];
        $link->setExpiresAt(new DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $guest = $this->asGuest();
        $guest->request('GET', $this->pathOf($url));

        self::assertStringContainsString('unavailable', $this->unavailableMarker($guest->getResponse()->getContent()));
    }

    public function testOpeningIsRecordedTheFirstTimeAndTheLast(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space, 'camille@societe.test')['url'];

        $guest = $this->asGuest();
        $guest->request('GET', $this->pathOf($url));

        $this->entityManager->clear();
        $link = $this->links->findAll()[0];

        // Two columns because they answer two questions: whether the mail ever
        // arrived, and whether somebody keeps coming back.
        self::assertNotNull($link->getFirstOpenedAt());
        self::assertNotNull($link->getLastUsedAt());
    }

    public function testALinkOfAnotherSpaceIsNotFoundUnderThisOne(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace('Voisin', '39860733100024');

        $this->issue($theirs, 'voisin@societe.test');
        $foreign = $this->links->findForSpace($theirs)[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/%d/revoke', $mine->getId(), $foreign->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->links->findForSpace($theirs)[0]->isRevoked());
    }

    public function testAnInvalidEmailIsRefusedUnderItsField(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'pas-une-adresse',
            'label' => 'Un nom valide',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('recipientEmail', $this->payload()['errors']);
        self::assertSame([], $this->links->findAll());
    }

    /**
     * This client, with no identity.
     *
     * Dropping the cookies rather than booting a second client: the kernel may
     * only boot once per test, and what the guest page has to be checked
     * against is precisely the absence of a session.
     */
    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();

        return $this->client;
    }

    /** @return array<string, mixed> */
    private function issue(CustomerSpace $space, string $email): array
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => $email,
            'label' => 'Camille, gérante',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->payload();
    }

    private function givenSpace(string $customerName = 'Client du lien', string $siret = '73282932000074'): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('link@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace de '.$customerName,
            'customerId' => $customer->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenItem(CustomerSpace $space, string $title, ?string $scheduledAt): void
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
            'scheduledAt' => $scheduledAt,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** The path half of an absolute URL, which is what the test client wants. */
    private function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    /**
     * "unavailable" when the refusal page was served, "opened" otherwise.
     *
     * Matched on a fragment with no apostrophe in it: Twig escapes one to
     * `&#039;`, so the sentence as written in the translation file never
     * appears in the HTML.
     */
    private function unavailableMarker(string|false $body): string
    {
        return str_contains((string) $body, 'plus valide') ? 'unavailable' : 'opened';
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
