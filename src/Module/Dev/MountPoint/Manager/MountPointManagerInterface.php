<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\MountPoint\Manager;

use Aurora\Module\Dev\MountPoint\Dto\MountPointInputInterface;
use Aurora\Module\Dev\MountPoint\Entity\MountPointInterface;
use DateTimeImmutable;

interface MountPointManagerInterface
{
    public function create(MountPointInputInterface $input): MountPointInterface;

    public function update(MountPointInterface $mountPoint, MountPointInputInterface $input): void;

    public function recordTestResult(MountPointInterface $mountPoint, bool $successful, ?DateTimeImmutable $at = null): void;

    public function delete(MountPointInterface $mountPoint): void;
}
