<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Entity;

use DateTimeImmutable;

interface DeployedInstanceInterface
{
    public function getId(): ?int;

    public function getInstanceId(): string;

    public function getDomain(): ?string;

    public function getHostname(): ?string;

    public function getAppVersion(): ?string;

    public function getPhpVersion(): ?string;

    public function isSignatureValid(): bool;

    public function isKnown(): bool;

    public function getPingCount(): int;

    public function getLastIp(): ?string;

    public function getFirstSeenAt(): DateTimeImmutable;

    public function getLastSeenAt(): DateTimeImmutable;
}
