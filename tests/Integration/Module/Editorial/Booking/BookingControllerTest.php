<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Booking;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A visitor's booking, from a chosen instant to a tentative event on the
 * shared calendar - and the same slot refused a second time.
 */
final class BookingControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testABookingHoldsItsSlotAndRefusesTheSecondClaim(): void
    {
        $postId = $this->page();
        $at = (new DateTimeImmutable('+2 hours'))->setTime((int) (new DateTimeImmutable('+2 hours'))->format('H'), 0)->format(DATE_ATOM);

        $first = $this->book($postId, $at, 'Camille Laurent', 'camille@example.com');
        self::assertTrue($first['success'] ?? false, json_encode($first));

        $second = $this->book($postId, $at, 'Un autre', 'autre@example.com');
        self::assertFalse($second['success'] ?? true);

        $events = static::getContainer()->get(PlanningEventRepository::class)->findAll();
        self::assertCount(1, array_filter($events, static fn ($e) => 'Camille Laurent' === $e->getTitle()));
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        $at = (new DateTimeImmutable('+3 hours'))->format(DATE_ATOM);

        self::assertFalse($this->book($this->page(), $at, 'X', 'not-an-email')['success'] ?? true);
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

        $allDay = [['00:00', '23:59']];
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
                ],
            ]]]);

        $post->translate('fr')->setTitle('Réservation')->setSlug('reservation-'.bin2hex(random_bytes(4)))->setGrid(['zones' => []]);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return (int) $post->getId();
    }
}
