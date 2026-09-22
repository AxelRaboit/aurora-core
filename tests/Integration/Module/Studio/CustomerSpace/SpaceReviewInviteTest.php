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
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

use function json_decode;
use function sprintf;

/**
 * Demander à un client d'aller relire, et tout ce que l'envoi ne doit pas faire.
 *
 * L'action est la seule du module qui écrit dans la boîte de quelqu'un et qui
 * ferme une adresse encore valide. Ce qui se vérifie ici tient à ces deux
 * conséquences : qu'elle ne parte que lorsqu'il y a vraiment à relire, qu'elle
 * ne parle qu'à ceux qui peuvent répondre, et que l'adresse qu'elle envoie
 * fonctionne quand l'ancienne a cessé de fonctionner.
 */
final class SpaceReviewInviteTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkRepository $links;

    private SpaceContentColumnRepository $columns;

    private SpaceContentItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(SpaceAccessLinkRepository::class);
        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->items = $container->get(SpaceContentItemRepository::class);

        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        foreach ([SpaceContentComment::class, SpaceContentItem::class, SpaceAccessLink::class, SpaceContentColumn::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheClientIsWrittenToWithAFreshAddress(): void
    {
        $space = $this->givenSpace();
        $this->givenScheduledItem($space, 'À relire');
        $previous = $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload = $this->payload();
        self::assertSame(1, $payload['awaiting']);
        self::assertSame(1, $payload['notified']);

        $mails = $this->mailerMessages();
        self::assertCount(1, $mails);
        self::assertSame('camille@societe.test', $mails[0]->getTo()[0]->getAddress());
        // Le nombre est dans le message : c'est lui qui décide si on ouvre
        // maintenant ou ce soir.
        self::assertStringContainsString('1', $mails[0]->getHtmlBody());

        // L'ancienne adresse est fermée, et une neuve existe pour la même
        // personne : le client en a toujours exactement une valide.
        $this->entityManager->clear();
        $stored = $this->links->find($previous);
        self::assertNotNull($stored->getRevokedAt());

        $live = $this->links->findApproversForSpace($this->reload($space), new DateTimeImmutable());
        self::assertCount(1, $live);
        self::assertNotSame($previous, $live[0]->getId());
        self::assertSame('camille@societe.test', $live[0]->getRecipientEmail());
    }

    /**
     * Un courriel annonçant zéro publication en attente est celui qui apprend à
     * ignorer les suivants.
     */
    public function testNothingIsSentWhenNothingIsWaiting(): void
    {
        $space = $this->givenSpace();
        $previous = $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        $payload = $this->payload();
        self::assertSame(0, $payload['awaiting']);
        self::assertSame(0, $payload['notified']);
        self::assertCount(0, $this->mailerMessages());

        // Et surtout, l'adresse du client n'a pas été fermée pour rien.
        $this->entityManager->clear();
        self::assertNull($this->links->find($previous)->getRevokedAt());
    }

    /**
     * Une carte sans date, ou décochée du calendrier, n'est pas sous les yeux du
     * client : lui demander d'y répondre serait lui montrer une porte fermée.
     */
    public function testACardTheClientCannotSeeDoesNotCount(): void
    {
        $space = $this->givenSpace();
        $this->givenItem($space, 'Jamais datée');
        $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        self::assertSame(0, $this->payload()['awaiting']);
        self::assertCount(0, $this->mailerMessages());
    }

    /** Un lien en lecture seule recevrait une demande qu'il ne peut pas honorer. */
    public function testAReadOnlyLinkIsNotWrittenTo(): void
    {
        $space = $this->givenSpace();
        $this->givenScheduledItem($space, 'À relire');
        $previous = $this->issue($space, canApprove: false);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        $payload = $this->payload();
        // Il y a bien quelque chose à relire, mais personne à qui le demander.
        self::assertSame(1, $payload['awaiting']);
        self::assertSame(0, $payload['notified']);
        self::assertCount(0, $this->mailerMessages());

        $this->entityManager->clear();
        self::assertNull($this->links->find($previous)->getRevokedAt());
    }

    /**
     * Le courriel d'abord, la révocation ensuite.
     *
     * C'est le défaut que le premier essai en local a montré : le serveur de
     * messagerie était éteint, l'adresse avait déjà été fermée, et le client se
     * serait retrouvé dehors sans avoir reçu celle qui la remplaçait.
     */
    public function testAFailedEmailLeavesTheClientTheirAddress(): void
    {
        // Sans cela le client redémarre le noyau à chaque requête et jette le
        // double avec lui ; et posé avant la moindre requête, sinon le service
        // est déjà initialisé et ne se remplace plus.
        $this->client->disableReboot();
        static::getContainer()->set('mailer.mailer', new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new RuntimeException('Le serveur de messagerie ne répond pas.');
            }
        });

        $space = $this->givenSpace();
        $this->givenScheduledItem($space, 'À relire');
        $previous = $this->issue($space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/review', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // Personne n'a été prévenu, et l'écran le dit plutôt que d'annoncer un
        // envoi qui n'a pas eu lieu.
        self::assertSame(0, $this->payload()['notified']);

        $this->entityManager->clear();
        self::assertNull($this->links->find($previous)->getRevokedAt());
    }

    private function loginAdmin(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    private function issue(CustomerSpace $space, bool $canApprove = true): int
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canApprove' => $canApprove,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->links->findForSpace($space)[0]->getId();
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client à relancer')
            ->setSiret('73282932000074')
            ->setContractualEmail('review@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace à relire',
            'customerId' => $customer->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function reload(CustomerSpace $space): CustomerSpace
    {
        return $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
    }

    private function givenItem(CustomerSpace $space, string $title): int
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ($title === $item['title']) {
                return $item['id'];
            }
        }

        self::fail(sprintf('La carte "%s" n\'a pas été créée.', $title));
    }

    private function givenScheduledItem(CustomerSpace $space, string $title): int
    {
        $id = $this->givenItem($space, $title);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/update', $space->getId(), $id), [
            'title' => $title,
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
            'scheduledAt' => '2026-12-01T09:00',
            'showOnCalendar' => true,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        self::assertNotNull($this->items->find($id)->getScheduledAt());

        return $id;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * Pas `getMailerMessages()` : messenger est actif, donc chaque envoi est
     * rapporté deux fois, une fois mis en file et une fois délivré.
     *
     * @return list<Email>
     */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $event) {
            if ($event->isQueued()) {
                continue;
            }

            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $messages[] = $message;
        }

        return $messages;
    }
}
