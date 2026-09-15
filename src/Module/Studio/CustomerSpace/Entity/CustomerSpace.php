<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerSpaceRepository::class)]
#[ORM\Table(name: 'core_studio_customer_spaces')]
class CustomerSpace extends AbstractCustomerSpace
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_customer_space_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
