<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Entity;

use Aurora\Module\Dev\MountPoint\Entity\MountPoint;
use Aurora\Module\Dev\MountPoint\Enum\MountPointTypeEnum;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MountPointTest extends TestCase
{
    public function testIdIsNullByDefault(): void
    {
        self::assertNull((new MountPoint())->getId());
    }

    public function testDefaultValues(): void
    {
        $mountPoint = new MountPoint();

        self::assertNull($mountPoint->getPort());
        self::assertNull($mountPoint->getUsername());
        self::assertNull($mountPoint->getPassword());
        self::assertNull($mountPoint->getDatabase());
        self::assertNull($mountPoint->getSshPublicKey());
        self::assertNull($mountPoint->getSshPrivateKey());
        self::assertSame([], $mountPoint->getConfig());
        self::assertNull($mountPoint->getLastTestedAt());
        self::assertNull($mountPoint->isLastTestSuccessful());
    }

    public function testNameAndTypeAndHost(): void
    {
        $mountPoint = (new MountPoint())
            ->setName('Production DB')
            ->setType(MountPointTypeEnum::Database)
            ->setHost('db.example.com');

        self::assertSame('Production DB', $mountPoint->getName());
        self::assertSame(MountPointTypeEnum::Database, $mountPoint->getType());
        self::assertSame('db.example.com', $mountPoint->getHost());
    }

    public function testCredentialsGettersAndSetters(): void
    {
        $mountPoint = (new MountPoint())
            ->setPort(5432)
            ->setUsername('admin')
            ->setPassword('secret')
            ->setDatabase('mydb');

        self::assertSame(5432, $mountPoint->getPort());
        self::assertSame('admin', $mountPoint->getUsername());
        self::assertSame('secret', $mountPoint->getPassword());
        self::assertSame('mydb', $mountPoint->getDatabase());
    }

    public function testSshKeysGettersAndSetters(): void
    {
        $mountPoint = (new MountPoint())
            ->setSshPublicKey('ssh-rsa AAAA...')
            ->setSshPrivateKey('-----BEGIN PRIVATE KEY-----');

        self::assertSame('ssh-rsa AAAA...', $mountPoint->getSshPublicKey());
        self::assertSame('-----BEGIN PRIVATE KEY-----', $mountPoint->getSshPrivateKey());
    }

    public function testConfigGetterAndSetter(): void
    {
        $config = ['timeout' => 30, 'ssl' => true];
        $mountPoint = (new MountPoint())->setConfig($config);

        self::assertSame($config, $mountPoint->getConfig());
    }

    public function testLastTestedAtAndSuccessful(): void
    {
        $date = new DateTimeImmutable('2026-01-15');
        $mountPoint = (new MountPoint())->setLastTestedAt($date)->setLastTestSuccessful(true);

        self::assertSame($date, $mountPoint->getLastTestedAt());
        self::assertTrue($mountPoint->isLastTestSuccessful());

        $mountPoint->setLastTestSuccessful(false);
        self::assertFalse($mountPoint->isLastTestSuccessful());
    }
}
