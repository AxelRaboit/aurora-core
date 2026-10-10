<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Entity;

use Aurora\Module\Studio\ClientNotice\Repository\ClientNoticeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientNoticeRepository::class)]
#[ORM\Table(name: 'core_studio_client_notices')]
#[ORM\Index(name: 'idx_client_notice_link_pending', columns: ['link_id', 'seen_at', 'emailed_at'])]
class ClientNotice extends AbstractClientNotice
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_client_notice_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
