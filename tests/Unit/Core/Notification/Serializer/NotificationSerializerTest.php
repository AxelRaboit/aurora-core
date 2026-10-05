<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Notification\Serializer;

use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Core\Notification\Serializer\NotificationSerializer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NotificationSerializerTest extends TestCase
{
    private function makeNotification(?DateTimeImmutable $readAt = null, ?array $data = null): NotificationInterface
    {
        $notif = $this->createStub(NotificationInterface::class);
        $notif->method('getId')->willReturn(1);
        $notif->method('getType')->willReturn('comment.new');
        $notif->method('getTitle')->willReturn('New comment');
        $notif->method('getBody')->willReturn('Someone commented');
        $notif->method('getUrl')->willReturn('/suite/editorial/comments/42');
        $notif->method('getData')->willReturn($data);
        $notif->method('getReadAt')->willReturn($readAt);
        $notif->method('getCreatedAt')->willReturn(new DateTimeImmutable('2026-01-15T10:00:00+00:00'));

        return $notif;
    }

    public function testSerializeReturnsExpectedShape(): void
    {
        $result = (new NotificationSerializer())->serialize($this->makeNotification());

        self::assertSame(1, $result['id']);
        self::assertSame('comment.new', $result['type']);
        self::assertSame('New comment', $result['title']);
        self::assertSame('Someone commented', $result['body']);
        self::assertSame('/suite/editorial/comments/42', $result['url']);
        self::assertNull($result['data']);
        self::assertNull($result['readAt']);
        self::assertSame('2026-01-15T10:00:00+00:00', $result['createdAt']);
    }

    public function testSerializeIncludesReadAt(): void
    {
        $readAt = new DateTimeImmutable('2026-01-15T11:00:00+00:00');
        $result = (new NotificationSerializer())->serialize($this->makeNotification(readAt: $readAt));

        self::assertSame('2026-01-15T11:00:00+00:00', $result['readAt']);
    }

    public function testSerializeIncludesData(): void
    {
        $data = ['postId' => 42];
        $result = (new NotificationSerializer())->serialize($this->makeNotification(data: $data));

        self::assertSame($data, $result['data']);
    }
}
