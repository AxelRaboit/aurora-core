<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * Seeing what a link shows, without being able to answer in the client's
 * place.
 *
 * **The preview is a real link, and that is the only way it tells the
 * truth.** The plain token only exists at creation; a page rebuilt with a
 * made-up token renders and answers nothing, so neither the Drive folder nor
 * the files appear in it, which is exactly what one came to check.
 *
 * The price of that honesty is that it has to be held in check: a link that
 * copies the rights could approve content on the client's behalf. What these
 * tests pin is that refusal, and the fact that it rests not on the rights but
 * on the nature of the link.
 */
final class SpaceAccessPreviewTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkManagerInterface $links;

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
        // The rate limiter's counter outlives the process: a class that writes
        // as a guest spends a shared hourly budget, and turns red on the
        // third run within the hour - through a 429 on a route the test did
        // not mean to exercise.
        $this->resetRateLimiter('space_guest_write');

        // The browser sets this header on every call, and public routes
        // require it: what protects them is a secret in the address, and an
        // address can be forwarded.
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        $this->links = $container->get(SpaceAccessLinkManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testThePreviewOpensTheClientPageForReal(): void
    {
        $link = $this->givenLink();

        $this->client->request('GET', sprintf('/workspace/%d/access/%d/preview', $link->getSpace()->getId(), $link->getId()));

        // A redirect to the real public route, and not a second way of
        // rendering the same page.
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The refusal rests on the nature of the link, not on its rights.
     *
     * The preview copies "can approve" so that the screen is the same; an
     * answer sent from it must still be refused, otherwise a careless click
     * records a verdict on the client's behalf.
     */
    public function testAPreviewCannotAnswerEvenThoughItMayOnPaper(): void
    {
        $source = $this->givenLink(canApprove: true);
        $preview = $this->links->preview($source);

        self::assertTrue($preview->canApprove(), 'le droit est bien recopié');

        $this->client->jsonRequest('POST', sprintf(
            '/spaces/%s/%s/items/1/answer',
            $preview->getSelector(),
            (string) $preview->getPlainToken(),
        ), ['approval' => 'approved']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * All the rights, conversation included: the preview lost it, and the
     * screen shown to the studio was no longer the client's.
     */
    public function testAPreviewCarriesEveryRightOfItsLink(): void
    {
        $source = $this->givenLink();
        $source->setCanChat(true)->setCanUpload(true)->setCanSeeDrive(true);
        $this->entityManager->flush();

        $preview = $this->links->preview($source);

        self::assertSame(
            [$source->canApprove(), $source->canComment(), true, true, true],
            [$preview->canApprove(), $preview->canComment(), $preview->canChat(), $preview->canUpload(), $preview->canSeeDrive()],
        );
    }

    /** A preview is nobody's recipient: the list ignores it. */
    public function testAPreviewNeverShowsInTheListOfLinks(): void
    {
        $source = $this->givenLink();
        $this->links->preview($source);

        $this->client->request('GET', sprintf('/workspace/%d/access', $source->getSpace()->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $links = $this->entityManager->getRepository(SpaceAccessLink::class)->findForSpace($source->getSpace());

        self::assertCount(1, $links);
        self::assertSame($source->getId(), $links[0]->getId());
    }

    /**
     * A single preview at a time.
     *
     * The previous one is deleted, otherwise a still valid address would
     * linger after the rights it was meant to show had changed.
     */
    public function testAskingTwiceReplacesTheFirstPreview(): void
    {
        $source = $this->givenLink();

        $first = $this->links->preview($source);
        $firstId = $first->getId();

        $second = $this->links->preview($source);

        self::assertNotSame($firstId, $second->getId());
        self::assertNull($this->entityManager->getRepository(SpaceAccessLink::class)->find($firstId));
    }

    /** It expires on its own, in minutes and not in days. */
    public function testAPreviewDiesOnItsOwnWithinTheHour(): void
    {
        $preview = $this->links->preview($this->givenLink());

        $inAnHour = new DateTimeImmutable('+1 hour');

        self::assertLessThan($inAnHour, $preview->getExpiresAt());
    }

    private function givenLink(bool $canApprove = true): SpaceAccessLinkInterface
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client de l\'aperçu')
            ->setSiret('73282932000074')
            ->setContractualEmail('apercu@example.test');

        $this->entityManager->persist($customer);

        $space = new CustomerSpace();
        $space
            ->setName('Espace de l\'aperçu')
            ->setCustomer($customer)
            ->setTimezone('Europe/Paris');

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $this->links->issue($space, 'client@example.test', 'Le client', 30, $canApprove, true);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
