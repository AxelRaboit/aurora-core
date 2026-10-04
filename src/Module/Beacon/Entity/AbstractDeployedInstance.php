<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per deployed instance of Aurora, not one per ping: the beacon
 * receiver upserts on {@see $instanceId}, so the table stays the size of the
 * number of live deployments rather than growing with traffic.
 *
 * Every field but the instance id is caller-reported and therefore untrusted;
 * the receiver caps lengths and never acts on a value here beyond displaying
 * it. {@see $known} and {@see $signatureValid} are the two the receiver
 * computes itself, so they are the ones to trust when telling your own
 * deployments from a copy (see LICENSE).
 */
#[ORM\MappedSuperclass]
abstract class AbstractDeployedInstance implements DeployedInstanceInterface
{
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $domain = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $hostname = null;

    #[ORM\Column(length: 40, nullable: true)]
    protected ?string $appVersion = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $phpVersion = null;

    #[ORM\Column]
    protected bool $signatureValid = false;

    #[ORM\Column]
    protected bool $known = false;

    #[ORM\Column]
    protected int $pingCount = 1;

    #[ORM\Column(length: 45, nullable: true)]
    protected ?string $lastIp = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $lastSeenAt;

    public function __construct(
        #[ORM\Column(length: 64, unique: true)]
        protected string $instanceId,
    ) {
        $this->firstSeenAt = new DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
    }

    /**
     * Fold a fresh ping into this row: refresh the reported fields, bump the
     * counter and the last-seen clock. First-seen is never touched.
     */
    public function touch(
        ?string $domain,
        ?string $hostname,
        ?string $appVersion,
        ?string $phpVersion,
        bool $signatureValid,
        bool $known,
        ?string $lastIp,
    ): static {
        $this->domain = $domain;
        $this->hostname = $hostname;
        $this->appVersion = $appVersion;
        $this->phpVersion = $phpVersion;
        $this->signatureValid = $signatureValid;
        $this->known = $known;
        $this->lastIp = $lastIp;
        ++$this->pingCount;
        $this->lastSeenAt = new DateTimeImmutable();

        return $this;
    }

    public function fill(
        ?string $domain,
        ?string $hostname,
        ?string $appVersion,
        ?string $phpVersion,
        bool $signatureValid,
        bool $known,
        ?string $lastIp,
    ): static {
        $this->domain = $domain;
        $this->hostname = $hostname;
        $this->appVersion = $appVersion;
        $this->phpVersion = $phpVersion;
        $this->signatureValid = $signatureValid;
        $this->known = $known;
        $this->lastIp = $lastIp;

        return $this;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function getPhpVersion(): ?string
    {
        return $this->phpVersion;
    }

    public function isSignatureValid(): bool
    {
        return $this->signatureValid;
    }

    public function isKnown(): bool
    {
        return $this->known;
    }

    public function getPingCount(): int
    {
        return $this->pingCount;
    }

    public function getLastIp(): ?string
    {
        return $this->lastIp;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
