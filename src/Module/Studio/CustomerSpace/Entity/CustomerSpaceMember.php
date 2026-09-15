<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerSpaceMemberRepository::class)]
#[ORM\Table(name: 'core_studio_customer_space_members')]
class CustomerSpaceMember extends AbstractCustomerSpaceMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_customer_space_member_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
