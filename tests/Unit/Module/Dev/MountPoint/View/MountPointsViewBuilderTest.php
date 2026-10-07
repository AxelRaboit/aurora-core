<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Dev\MountPoint\View;

use Aurora\Module\Dev\MountPoint\Entity\MountPoint;
use Aurora\Module\Dev\MountPoint\Repository\MountPointRepository;
use Aurora\Module\Dev\MountPoint\Serializer\MountPointSerializerInterface;
use Aurora\Module\Dev\MountPoint\View\MountPointsViewBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class MountPointsViewBuilderTest extends TestCase
{
    public function testListPayloadReturnsMountPointsAndTypes(): void
    {
        $repository = $this->createStub(MountPointRepository::class);
        $repository->method('findAllOrderedByName')->willReturn([new MountPoint()]);

        $serializer = $this->createStub(MountPointSerializerInterface::class);
        $serializer->method('serialize')->willReturn(['name' => 'X']);

        $payload = (new MountPointsViewBuilder($repository, $serializer, new IdentityTranslator()))->listPayload();

        self::assertArrayHasKey('mountPoints', $payload);
        self::assertArrayHasKey('types', $payload);
        self::assertCount(1, $payload['mountPoints']);
        self::assertNotEmpty($payload['types']);
    }

    public function testTypesContainValueAndLabel(): void
    {
        $repository = $this->createStub(MountPointRepository::class);
        $repository->method('findAllOrderedByName')->willReturn([]);

        $serializer = $this->createStub(MountPointSerializerInterface::class);

        $payload = (new MountPointsViewBuilder($repository, $serializer, new IdentityTranslator()))->listPayload();

        foreach ($payload['types'] as $type) {
            self::assertArrayHasKey('value', $type);
            self::assertArrayHasKey('label', $type);
        }
    }

    public function testIndexViewWrapsPayload(): void
    {
        $repository = $this->createStub(MountPointRepository::class);
        $serializer = $this->createStub(MountPointSerializerInterface::class);
        $builder = new MountPointsViewBuilder($repository, $serializer, new IdentityTranslator());

        $view = $builder->indexView(['mountPoints' => [], 'types' => []]);

        self::assertSame('mount_points', $view['tab']);
        self::assertSame(['mountPoints' => [], 'types' => []], $view['mountPoints']);
    }
}
