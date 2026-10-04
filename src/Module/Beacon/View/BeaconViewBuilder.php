<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\View;

use Aurora\Module\Beacon\Entity\DeployedInstanceInterface;
use Aurora\Module\Beacon\Repository\DeployedInstanceRepository;
use Aurora\Module\Beacon\Service\KnownDomains;
use DateTimeInterface;

/**
 * Assembles the template/Vue payload for the beacon back-office screen: the
 * recorded instances (unknown first, so a lead is the first thing read) and
 * the editable allowlist.
 */
final readonly class BeaconViewBuilder
{
    public function __construct(
        private DeployedInstanceRepository $instances,
        private KnownDomains $knownDomains,
    ) {}

    /** @return array<string, mixed> */
    public function indexView(): array
    {
        $instances = array_map($this->serialize(...), $this->instances->findAllOrdered());

        // Leads first, then by most-recently seen.
        usort($instances, static fn (array $a, array $b): int => [$a['known'], $b['lastSeenAt']] <=> [$b['known'], $a['lastSeenAt']]);

        return [
            'instances' => $instances,
            'knownDomains' => $this->knownDomains->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(DeployedInstanceInterface $instance): array
    {
        return [
            'id' => $instance->getId(),
            'instanceId' => $instance->getInstanceId(),
            'domain' => $instance->getDomain(),
            'hostname' => $instance->getHostname(),
            'appVersion' => $instance->getAppVersion(),
            'phpVersion' => $instance->getPhpVersion(),
            'signatureValid' => $instance->isSignatureValid(),
            'known' => $instance->isKnown(),
            'pingCount' => $instance->getPingCount(),
            'lastIp' => $instance->getLastIp(),
            'firstSeenAt' => $instance->getFirstSeenAt()->format(DateTimeInterface::ATOM),
            'lastSeenAt' => $instance->getLastSeenAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
