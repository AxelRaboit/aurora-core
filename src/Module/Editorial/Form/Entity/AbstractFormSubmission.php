<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractFormSubmission implements FormSubmissionInterface
{
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: FormInterface::class, inversedBy: 'submissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected FormInterface $form;

    /**
     * Keyed by field id rather than by label: a label is translated and can
     * be edited, and a submission has to still mean the same thing a year
     * after someone rewords the question.
     *
     * @var array<string, string|list<string>>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $data = [];

    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $submittedAt;

    /**
     * Kept for one purpose: letting an administrator recognise abuse in the
     * submissions list. It is personal data, so it never leaves the
     * installation - in particular it is not in the webhook payload, where
     * the reference sent it to whatever third-party URL an admin had
     * configured. A visitor filled in a contact form; they did not agree to
     * have their address forwarded.
     */
    #[ORM\Column(length: 45, nullable: true)]
    protected ?string $ip = null;

    public function __construct()
    {
        $this->submittedAt = new DateTimeImmutable();
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getForm(): FormInterface
    {
        return $this->form;
    }

    public function setForm(FormInterface $form): static
    {
        $this->form = $form;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getSubmittedAt(): DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }
}
