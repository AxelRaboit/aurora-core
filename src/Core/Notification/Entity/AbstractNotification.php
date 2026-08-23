<?php

declare(strict_types=1);

namespace Aurora\Core\Notification\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractNotification implements NotificationInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $recipient;

    /** Free-form type slug - e.g. 'project.task.assigned', 'project.task.mentioned'. */
    #[ORM\Column(length: 80)]
    protected string $type;

    /** Human-readable title (already translated at write-time). */
    #[ORM\Column(length: 255)]
    protected string $title;

    /** Optional body. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $body = null;

    /** Deep link to the related entity ('/backend/project/projects/12'). */
    #[ORM\Column(length: 500, nullable: true)]
    protected ?string $url = null;

    /** Arbitrary JSON payload (entity ids, etc.). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $data = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $readAt = null;

    public function getRecipient(): CoreUserInterface
    {
        return $this->recipient;
    }

    public function setRecipient(CoreUserInterface $user): static
    {
        $this->recipient = $user;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markAsRead(): static
    {
        $this->readAt = new DateTimeImmutable();

        return $this;
    }
}
