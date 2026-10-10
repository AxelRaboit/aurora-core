<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Entity;

use Aurora\Module\Studio\CustomerInteraction\Repository\CustomerInteractionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerInteractionRepository::class)]
#[ORM\Table(name: 'core_studio_customer_interactions')]
#[ORM\Index(name: 'idx_customer_interaction_occurred', columns: ['customer_id', 'occurred_at'])]
class CustomerInteraction extends AbstractCustomerInteraction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_customer_interaction_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
