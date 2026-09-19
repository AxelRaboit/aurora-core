<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * A space's dates reach the shared calendar, and say whose they are.
 *
 * The producer knows nothing about calendars: it announces into core and
 * Planning listens. What this pins is the part that is easy to get subtly
 * wrong - one calendar for every space rather than one each, and a colour that
 * tells the clients apart inside it.
 */
final class SpaceContentSchedulingTest extends IntegrationTestCase
{
    private const string SOURCE = 'studio.space_content';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columns;

    private PlanningEventRepository $events;

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
        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->events = $container->get(PlanningEventRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceContentItem::class, SpaceContentColumn::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        // The module calendar and its entries, which the announcements made.
        $this->entityManager->createQuery(sprintf('DELETE FROM %s e WHERE e.sourceType = :source', PlanningEvent::class))
            ->setParameter('source', self::SOURCE)
            ->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s p WHERE p.sourceType = :source', Planning::class))
            ->setParameter('source', self::SOURCE)
            ->execute();

        parent::tearDown();
    }

    public function testAScheduledCardLandsOnTheSharedCalendar(): void
    {
        $space = $this->givenSpace('Espace annoncé', 3);
        $item = $this->givenItem($space, 'Journée portes ouvertes', '2026-11-12T10:00');

        $entry = $this->events->findBySource(self::SOURCE, $item['id']);
        self::assertNotNull($entry, 'the date reached the calendar');

        self::assertSame('Journée portes ouvertes', $entry->getTitle());
        // The provenance names the space and not the module: a reader looking
        // at a busy week has to know which client a date belongs to, and
        // "Espaces clients" told them the same thing eight times.
        self::assertSame('Espace annoncé', $entry->getSourceLabel());
        // The space's own palette slot, which is what tells two clients apart
        // inside a single shared calendar.
        self::assertSame(3, $entry->getEffectiveColourSlot());
        // Written by a module, so the calendar refuses to let it be edited
        // there - the next announcement would rewrite it anyway.
        self::assertTrue($entry->isFromModule());
    }

    public function testEverySpaceSharesOneCalendar(): void
    {
        $first = $this->givenSpace('Premier espace', 2);
        $second = $this->givenSpace('Second espace', 5, 'Autre société', '39860733100024');

        $this->givenItem($first, 'Un', '2026-11-12T10:00');
        $this->givenItem($second, 'Deux', '2026-11-13T10:00');

        $plannings = $this->entityManager->getRepository(Planning::class)
            ->findBy(['sourceType' => self::SOURCE]);

        // One, not two. A calendar per space would put a row in every member's
        // sidebar for every client, because `findVisibleTo` returns every
        // shared calendar to everybody.
        self::assertCount(1, $plannings);
    }

    public function testClearingTheDateTakesTheCardOffTheCalendar(): void
    {
        $space = $this->givenSpace('Espace déprogrammé', 1);
        $item = $this->givenItem($space, 'À reporter', '2026-11-12T10:00');

        self::assertNotNull($this->events->findBySource(self::SOURCE, $item['id']));

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/schedule', $space->getId(), $item['id']), [
            'scheduledAt' => null,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // A card sent back to the board leaves the calendar. It is still work,
        // it just has no date any more.
        self::assertNull($this->events->findBySource(self::SOURCE, $item['id']));
    }

    /**
     * Une échéance de studio, pas une parution.
     *
     * La carte garde sa date et reste sur le tableau ; ce qui change est
     * qu'elle ne s'invite ni dans le mois de l'espace ni dans l'agenda
     * partagé. C'est ce second point qui se serait oublié : un calendrier sur
     * deux aurait continué de la montrer, et la case aurait menti.
     */
    public function testACardKeptOffTheCalendarIsNeverAnnounced(): void
    {
        $space = $this->givenSpace('Espace interne', 2);
        $item = $this->givenItem($space, 'Relancer le photographe', '2026-11-12T10:00', showOnCalendar: false);

        self::assertFalse($item['showOnCalendar']);
        // La date est bien gardée : c'est une échéance, pas rien.
        self::assertNotNull($item['scheduledAt']);
        self::assertNull($this->events->findBySource(self::SOURCE, $item['id']));
    }

    /** Recocher la remet dans les deux calendriers, sans retaper la date. */
    public function testCheckingItBackPutsTheCardOnTheCalendarAgain(): void
    {
        $space = $this->givenSpace('Espace repris', 6);
        $item = $this->givenItem($space, 'À rendre publique', '2026-11-12T10:00', showOnCalendar: false);

        self::assertNull($this->events->findBySource(self::SOURCE, $item['id']));

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/update', $space->getId(), $item['id']), [
            'title' => 'À rendre publique',
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
            'scheduledAt' => '2026-11-12T10:00',
            'showOnCalendar' => true,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->events->findBySource(self::SOURCE, $item['id']));
    }

    /**
     * Un appel qui ne connaît pas le champ ne fait disparaître personne.
     *
     * C'est le défaut qu'un booléen ajouté à une entrée existante produit :
     * absent du corps, il vaudrait faux, et toutes les cartes enregistrées par
     * un écran non mis à jour quitteraient le calendrier en silence.
     */
    public function testAPayloadWithoutTheFieldLeavesTheCardOnTheCalendar(): void
    {
        $space = $this->givenSpace('Espace par défaut', 7);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => 'Sans le champ',
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
            'scheduledAt' => '2026-11-12T10:00',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ('Sans le champ' === $item['title']) {
                self::assertTrue($item['showOnCalendar']);
                self::assertNotNull($this->events->findBySource(self::SOURCE, $item['id']));

                return;
            }
        }

        self::fail('the card was not in the answer');
    }

    public function testDeletingACardTakesItsDateWithIt(): void
    {
        $space = $this->givenSpace('Espace supprimé', 4);
        $item = $this->givenItem($space, 'Annulée', '2026-11-12T10:00');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/delete', $space->getId(), $item['id']));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->events->findBySource(self::SOURCE, $item['id']));
    }

    public function testACardWithNoDateNeverReachesTheCalendar(): void
    {
        $space = $this->givenSpace('Espace sans date', 6);
        $item = $this->givenItem($space, 'Une idée', null);

        self::assertNull($this->events->findBySource(self::SOURCE, $item['id']));
    }

    private function givenSpace(
        string $name,
        int $colourSlot,
        string $customerName = 'Client annoncé',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('schedule@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => $name,
            'customerId' => $customer->getId(),
            'colourSlot' => $colourSlot,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /** @return array<string, mixed> */
    private function givenItem(CustomerSpace $space, string $title, ?string $scheduledAt, bool $showOnCalendar = true): array
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
            'scheduledAt' => $scheduledAt,
            'showOnCalendar' => $showOnCalendar,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }

        self::fail(sprintf('the card "%s" was not in the answer', $title));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
