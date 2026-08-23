<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Notification\Manager;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Core\Notification\Manager\NotificationManager;
use Aurora\Core\Notification\Repository\NotificationRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class NotificationManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private NotificationRepository $notificationRepository;
    private NotificationManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->notificationRepository = $this->createMock(NotificationRepository::class);
        $this->manager = new NotificationManager(
            $this->entityManager,
            $this->notificationRepository,
        );
    }

    private function makeUser(int $id = 1): User
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    public function testNotifyAssignsAllFieldsAndPersists(): void
    {
        $recipient = $this->createStub(CoreUserInterface::class);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $notification = $this->manager->notify(
            $recipient,
            'order.paid',
            'Order paid',
            body: 'Order #42 is paid',
            url: '/backend/orders/42',
            data: ['orderId' => 42],
        );

        self::assertSame($recipient, $notification->getRecipient());
        self::assertSame('order.paid', $notification->getType());
        self::assertSame('Order paid', $notification->getTitle());
        self::assertSame('Order #42 is paid', $notification->getBody());
        self::assertSame('/backend/orders/42', $notification->getUrl());
        self::assertSame(['orderId' => 42], $notification->getData());
        // Fresh notification - not read yet.
        self::assertNull($notification->getReadAt());
    }

    public function testNotifyAcceptsNullOptionalArguments(): void
    {
        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $notification = $this->manager->notify(
            $this->createStub(CoreUserInterface::class),
            'system.broadcast',
            'Hello',
        );

        self::assertNull($notification->getBody());
        self::assertNull($notification->getUrl());
        // Empty data array is the documented default (the entity coerces
        // to nullable array - getData() returning [] is the contract).
        self::assertSame([], $notification->getData());
    }

    public function testMarkReadFlushesOnceWhenNotAlreadyRead(): void
    {
        $notification = new Notification();
        // Simulated: notification was never read → no readAt yet.

        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->markRead($notification);

        self::assertInstanceOf(DateTimeImmutable::class, $notification->getReadAt());
    }

    public function testMarkReadIsIdempotentForAlreadyReadNotification(): void
    {
        $notification = new Notification();
        $notification->markAsRead();
        $firstReadAt = $notification->getReadAt();

        // Already-read → manager must not call flush again (saves a DB
        // round-trip for users opening the bell repeatedly).
        $this->entityManager->expects(self::never())->method('flush');

        $this->manager->markRead($notification);

        self::assertSame($firstReadAt, $notification->getReadAt());
    }

    public function testMarkAllReadForUserDelegatesToRepository(): void
    {
        $user = $this->makeUser();

        $this->notificationRepository->expects(self::once())
            ->method('markAllReadForUser')
            ->with($user)
            ->willReturn(7);

        self::assertSame(7, $this->manager->markAllReadForUser($user));
    }

    public function testDeleteRemovesEntityAndFlushes(): void
    {
        $notification = $this->createMock(NotificationInterface::class);

        $this->entityManager->expects(self::once())->method('remove')->with($notification);
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->delete($notification);
    }

    public function testDeleteAllForUserDelegatesToRepository(): void
    {
        $user = $this->makeUser();

        $this->notificationRepository->expects(self::once())
            ->method('deleteAllForUser')
            ->with($user)
            ->willReturn(3);

        self::assertSame(3, $this->manager->deleteAllForUser($user));
    }
}
