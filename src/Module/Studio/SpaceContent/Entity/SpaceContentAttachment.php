<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpaceContentAttachmentRepository::class)]
#[ORM\Table(name: 'core_studio_space_content_attachments')]
#[ORM\Index(name: 'idx_space_attachment_item', columns: ['item_id', 'position'])]
class SpaceContentAttachment extends AbstractSpaceContentAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_space_content_attachment_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
