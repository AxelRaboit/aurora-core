<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Entity;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testIdIsNullByDefault(): void
    {
        self::assertNull((new Notification())->getId());
    }

    public function testDefaultValues(): void
    {
        $notification = new Notification();

        self::assertNull($notification->getBody());
        self::assertNull($notification->getUrl());
        self::assertNull($notification->getData());
        self::assertNull($notification->getReadAt());
    }

    public function testRecipientGetterAndSetter(): void
    {
        $user = new User();
        $notification = (new Notification())->setRecipient($user);

        self::assertSame($user, $notification->getRecipient());
    }

    public function testTypeAndTitleGettersAndSetters(): void
    {
        $notification = (new Notification())->setType('comment.new')->setTitle('New comment');

        self::assertSame('comment.new', $notification->getType());
        self::assertSame('New comment', $notification->getTitle());
    }

    public function testBodyAndUrlGettersAndSetters(): void
    {
        $notification = (new Notification())
            ->setBody('Someone commented on your post')
            ->setUrl('/suite/editorial/posts/42');

        self::assertSame('Someone commented on your post', $notification->getBody());
        self::assertSame('/suite/editorial/posts/42', $notification->getUrl());
    }

    public function testDataGetterAndSetter(): void
    {
        $data = ['postId' => 42, 'authorId' => 7];
        $notification = (new Notification())->setData($data);

        self::assertSame($data, $notification->getData());

        $notification->setData(null);
        self::assertNull($notification->getData());
    }

    public function testMarkAsReadSetsTimestamp(): void
    {
        $notification = new Notification();

        $before = new DateTimeImmutable();
        $notification->markAsRead();
        $after = new DateTimeImmutable();

        self::assertNotNull($notification->getReadAt());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $notification->getReadAt()->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $notification->getReadAt()->getTimestamp());
    }
}
