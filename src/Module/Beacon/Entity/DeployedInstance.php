<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Entity;

use Aurora\Module\Beacon\Repository\DeployedInstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeployedInstanceRepository::class)]
#[ORM\Table(name: 'beacon_instances')]
class DeployedInstance extends AbstractDeployedInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_beacon_instance_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
