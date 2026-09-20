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
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * Voir ce qu'un lien montre, sans pouvoir répondre à la place du client.
 *
 * **L'aperçu est un vrai lien, et c'est la seule façon qu'il dise vrai.** Le
 * jeton en clair n'existe qu'à la création ; une page reconstruite avec un
 * jeton inventé s'affiche et ne répond à rien, donc ni le dossier Drive ni
 * les fichiers n'y apparaissent, c'est-à-dire justement ce qu'on venait
 * vérifier.
 *
 * Le prix de cette honnêteté est qu'il faut le tenir : un lien qui recopie
 * les droits pourrait valider un contenu au nom du client. Ce que ces tests
 * pinnent, c'est ce refus, et le fait qu'il ne tienne pas aux droits mais à
 * la nature du lien.
 */
final class SpaceAccessPreviewTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkManagerInterface $links;

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

        // Une redirection vers la vraie route publique, et non une seconde
        // façon de rendre la même page.
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Le refus tient à la nature du lien, pas à ses droits.
     *
     * L'aperçu recopie « peut valider » pour que l'écran soit le même ; une
     * réponse envoyée depuis lui doit malgré tout être refusée, sinon un clic
     * distrait enregistre un verdict au nom du client.
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

    /** Un aperçu n'est le destinataire de personne : la liste l'ignore. */
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
     * Un seul aperçu à la fois.
     *
     * Le précédent est supprimé, sans quoi une adresse encore valide
     * traînerait après qu'on a changé les droits qu'elle était censée montrer.
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

    /** Il expire de lui-même, en minutes et non en jours. */
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
