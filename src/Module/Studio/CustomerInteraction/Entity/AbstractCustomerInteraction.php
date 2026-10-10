<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerInteraction\Enum\CustomerInteractionKindEnum;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One exchange with a customer: a call, an email, a meeting.
 *
 * **The history of a relationship, not a log of the software.** The audit
 * already records what was clicked; this records what was said, by whoever
 * said it, so the next person to call back knows where things were left.
 * Hence a date chosen by hand - a call is often written down the day after -
 * rather than the moment the row was created.
 *
 * **Never shown to the customer.** It is the studio's memory of them, written
 * the way one writes for oneself.
 *
 * **The author is stored twice**, like a space comment: the account, which
 * may be deleted, and the name it had, which may not disappear with it.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractCustomerInteraction implements CustomerInteractionInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: CustomerInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerInterface $customer;

    #[ORM\Column(length: 20, enumType: CustomerInteractionKindEnum::class)]
    protected CustomerInteractionKindEnum $kind = CustomerInteractionKindEnum::Note;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::TEXT)]
    protected string $summary = '';

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $authorUser = null;

    #[ORM\Column(length: 180)]
    protected string $authorLabel = '';

    abstract public function getId(): ?int;

    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(CustomerInterface $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getKind(): CustomerInteractionKindEnum
    {
        return $this->kind;
    }

    public function setKind(CustomerInteractionKindEnum $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getAuthorUser(): ?CoreUserInterface
    {
        return $this->authorUser;
    }

    public function getAuthorLabel(): string
    {
        return $this->authorLabel;
    }

    /**
     * The label is passed rather than read off the account: what a
     * `CoreUserInterface` is called is not part of that contract.
     */
    public function setAuthor(?CoreUserInterface $authorUser, string $authorLabel): static
    {
        $this->authorUser = $authorUser;
        $this->authorLabel = $authorLabel;

        return $this;
    }
}
