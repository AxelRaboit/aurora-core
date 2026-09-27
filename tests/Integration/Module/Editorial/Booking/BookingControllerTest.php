<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Booking;

use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A visitor's booking, from a chosen instant to a tentative event on the
 * shared calendar - and the same slot refused a second time.
 */
final class BookingControllerTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // Ten bookings an hour per address, and this class makes several:
        // without this, a fourth run within the hour goes red.
        $this->resetRateLimiter('editorial_booking');
    }

    public function testABookingHoldsItsSlotAndRefusesTheSecondClaim(): void
    {
        $postId = $this->page();
        $at = $this->tomorrowAt('10:00');

        $first = $this->book($postId, $at, 'Camille Laurent', 'camille@example.com');
        self::assertTrue($first['success'] ?? false, json_encode($first));

        $second = $this->book($postId, $at, 'Un autre', 'autre@example.com');
        self::assertSame('frontend.editorial.grid.booking.taken', $second['error'] ?? null);

        $events = static::getContainer()->get(PlanningEventRepository::class)->findAll();
        self::assertCount(1, array_filter($events, static fn ($e) => 'Camille Laurent' === $e->getTitle()));
    }

    /**
     * Each booking is a source of its own. They used to share the page's id,
     * and the calendar's unique source index refused the second booking on
     * the same page with a server error.
     */
    public function testTwoBookingsOnOnePageBothLand(): void
    {
        $postId = $this->page();

        self::assertTrue($this->book($postId, $this->tomorrowAt('14:00'), 'Camille', 'camille@example.com')['success'] ?? false);
        self::assertTrue($this->book($postId, $this->tomorrowAt('15:00'), 'Dominique', 'dominique@example.com')['success'] ?? false);

        $titles = array_map(static fn ($event): string => $event->getTitle(), $this->bookingsOnTheCalendar());
        self::assertContains('Camille', $titles);
        self::assertContains('Dominique', $titles);
    }

    /**
     * On the calendar at the instant the visitor chose, awaiting
     * confirmation, and the calendar's to confirm or cancel.
     */
    public function testABookingLandsAtItsInstantAndStaysTheCalendarsToAnswer(): void
    {
        $at = $this->tomorrowAt('16:00');
        self::assertTrue($this->book($this->page(), $at, 'Sacha', 'sacha@example.com')['success'] ?? false);

        $events = array_values(array_filter($this->bookingsOnTheCalendar(), static fn ($event): bool => 'Sacha' === $event->getTitle()));
        self::assertCount(1, $events);
        self::assertSame(new DateTimeImmutable($at)->getTimestamp(), $events[0]->getStartAt()->getTimestamp(), 'the Paris 16:00 the visitor chose, not 16:00 UTC');
        self::assertSame(PlanningEventStatusEnum::Tentative, $events[0]->getStatus());
        self::assertFalse($events[0]->isReadOnly());
        self::assertStringContainsString('sacha@example.com', (string) $events[0]->getDescription());
    }

    /**
     * A booking carries a name, an email and a phone number, and lands in a
     * calendar everybody who uses the calendar sees. Only the people who run
     * the pages get it.
     */
    public function testABookingIsHiddenFromCalendarUsersWhoDoNotRunThePages(): void
    {
        self::assertTrue($this->book($this->page(), $this->tomorrowAt('13:00'), 'Alix', 'alix@example.com')['success'] ?? false);

        $reader = new User();
        $reader->setEmail('agenda-seul@example.test')->setName('Agenda')->setType(UserTypeEnum::Backend)
            ->setRoles([UserRoleEnum::User->value])->setPassword('x')->setPrivileges(['planning.calendars.view']);
        $this->entityManager->persist($reader);
        $this->entityManager->flush();

        try {
            $this->client->loginUser($reader, 'admin');
            $window = sprintf('from=%s&to=%s', urlencode(new DateTimeImmutable('now')->format(DATE_ATOM)), urlencode(new DateTimeImmutable('+3 days')->format(DATE_ATOM)));
            $this->client->request('GET', '/backend/planning/events?'.$window);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());

            self::assertNotContains('Alix', array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['events'], 'title'));
        } finally {
            $this->entityManager->createQuery(sprintf("DELETE FROM %s u WHERE u.email = 'agenda-seul@example.test'", User::class))->execute();
        }
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        self::assertSame('frontend.editorial.grid.booking.invalid', $this->book($this->page(), $this->tomorrowAt('11:00'), 'X', 'not-an-email')['error'] ?? null);
    }

    /**
     * Only an instant the grid could have offered: off the slot grid, ending
     * after closing, or beyond the booking window, even on an empty calendar.
     */
    public function testAnInstantTheGridNeverOfferedIsRefused(): void
    {
        $postId = $this->page();

        foreach ([$this->tomorrowAt('10:17'), $this->tomorrowAt('18:30'), $this->tomorrowAt('07:00'), $this->inDaysAt(60, '10:00')] as $at) {
            self::assertSame('frontend.editorial.grid.booking.invalid', $this->book($postId, $at, 'Camille', 'camille@example.com')['error'] ?? null, $at);
        }
    }

    /** A form on another site cannot take a slot in a visitor's name. */
    public function testABookingPostedFromAnotherSiteIsRefused(): void
    {
        $this->client->request('POST', sprintf('/fr/booking/%d/b1', $this->page()), server: ['CONTENT_TYPE' => 'text/plain'], content: json_encode(['at' => $this->tomorrowAt('10:00'), 'name' => 'X', 'email' => 'x@example.com'], JSON_THROW_ON_ERROR));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** @return list<PlanningEventInterface> */
    private function bookingsOnTheCalendar(): array
    {
        $this->entityManager->clear();

        return static::getContainer()->get(PlanningEventRepository::class)->findBy(['sourceType' => BookingSlotFinder::SOURCE]);
    }

    private function tomorrowAt(string $clock): string
    {
        return $this->inDaysAt(1, $clock);
    }

    private function inDaysAt(int $days, string $clock): string
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $clock));

        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))
            ->modify(sprintf('+%d days', $days))
            ->setTime($hours, $minutes)
            ->format(DATE_ATOM);
    }

    /** @return array<string, mixed> */
    private function book(int $postId, string $at, string $name, string $email): array
    {
        $this->client->request('POST', sprintf('/fr/booking/%d/b1', $postId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], content: json_encode(['at' => $at, 'name' => $name, 'email' => $email], JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function page(): int
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'reservation-test']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('reservation-test')->setLabel('Réservation')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
        }

        // 09:00 to 18:00 every day, hour-long slots: 17:00 is the last start.
        $allDay = [['09:00', '18:00']];
        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setGridLayout(['enabled' => true, 'zones' => [[
                'id' => 'b1',
                'type' => 'appointmentBooking',
                'options' => [
                    'hours' => ['mon' => $allDay, 'tue' => $allDay, 'wed' => $allDay, 'thu' => $allDay, 'fri' => $allDay, 'sat' => $allDay, 'sun' => $allDay],
                    'slotDuration' => 60,
                    'bookingWindowDays' => 30,
                    'timezone' => 'Europe/Paris',
                ],
            ]]]);

        $post->translate('fr')->setTitle('Réservation')->setSlug('reservation-'.bin2hex(random_bytes(4)))->setGrid(['zones' => []]);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return (int) $post->getId();
    }
}
