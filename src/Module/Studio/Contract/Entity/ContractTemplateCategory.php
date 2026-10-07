<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Entity;

use Aurora\Module\Studio\Contract\Repository\ContractTemplateCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContractTemplateCategoryRepository::class)]
#[ORM\Table(name: 'core_contract_template_categories')]
class ContractTemplateCategory extends AbstractContractTemplateCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_contract_template_category_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
