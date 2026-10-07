<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Dev\MountPoint\Serializer;

use Aurora\Module\Dev\MountPoint\Entity\MountPointInterface;
use Aurora\Module\Dev\MountPoint\Enum\MountPointTypeEnum;
use Aurora\Module\Dev\MountPoint\Serializer\MountPointSerializer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MountPointSerializerTest extends TestCase
{
    private function makeMountPoint(
        ?string $password = 'secret',
        ?string $sshPrivateKey = null,
        ?DateTimeImmutable $lastTestedAt = null,
        ?bool $lastTestSuccessful = null,
    ): MountPointInterface {
        $mountPoint = $this->createStub(MountPointInterface::class);
        $mountPoint->method('getId')->willReturn(1);
        $mountPoint->method('getName')->willReturn('Prod DB');
        $mountPoint->method('getType')->willReturn(MountPointTypeEnum::Database);
        $mountPoint->method('getHost')->willReturn('db.example.com');
        $mountPoint->method('getPort')->willReturn(5432);
        $mountPoint->method('getUsername')->willReturn('admin');
        $mountPoint->method('getPassword')->willReturn($password);
        $mountPoint->method('getSshPrivateKey')->willReturn($sshPrivateKey);
        $mountPoint->method('getSshPublicKey')->willReturn('ssh-rsa AAAA...');
        $mountPoint->method('getDatabase')->willReturn('mydb');
        $mountPoint->method('getConfig')->willReturn([]);
        $mountPoint->method('getLastTestedAt')->willReturn($lastTestedAt);
        $mountPoint->method('isLastTestSuccessful')->willReturn($lastTestSuccessful);
        $mountPoint->method('getCreatedAt')->willReturn(new DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $mountPoint->method('getUpdatedAt')->willReturn(new DateTimeImmutable('2026-01-02T10:00:00+00:00'));

        return $mountPoint;
    }

    public function testSerializeReturnsExpectedShape(): void
    {
        $result = (new MountPointSerializer())->serialize($this->makeMountPoint());

        self::assertSame(1, $result['id']);
        self::assertSame('Prod DB', $result['name']);
        self::assertSame('database', $result['type']);
        self::assertSame('db.example.com', $result['host']);
        self::assertSame(5432, $result['port']);
        self::assertSame('admin', $result['username']);
        self::assertTrue($result['hasPassword']);
        self::assertFalse($result['hasSshPrivateKey']);
        self::assertSame('mydb', $result['database']);
        self::assertSame('2026-01-01T10:00:00+00:00', $result['createdAt']);
    }

    public function testHasPasswordReturnsFalseWhenNoPassword(): void
    {
        $result = (new MountPointSerializer())->serialize($this->makeMountPoint(password: null));

        self::assertFalse($result['hasPassword']);
    }

    public function testHasSshPrivateKeyReturnsTrueWhenSet(): void
    {
        $result = (new MountPointSerializer())->serialize($this->makeMountPoint(sshPrivateKey: 'private-key'));

        self::assertTrue($result['hasSshPrivateKey']);
    }

    public function testLastTestedAtReturnsNullWhenAbsent(): void
    {
        $result = (new MountPointSerializer())->serialize($this->makeMountPoint());

        self::assertNull($result['lastTestedAt']);
    }

    public function testLastTestedAtFormattedAsAtom(): void
    {
        $date = new DateTimeImmutable('2026-02-01T10:00:00+00:00');
        $result = (new MountPointSerializer())->serialize($this->makeMountPoint(lastTestedAt: $date, lastTestSuccessful: true));

        self::assertSame('2026-02-01T10:00:00+00:00', $result['lastTestedAt']);
        self::assertTrue($result['lastTestSuccessful']);
    }
}
