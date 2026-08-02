<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractForm implements FormInterface
{
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $reference = null;

    /** Where a submission is announced. Falls back to the site administrator. */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $notifyEmail = null;

    #[ORM\Column(length: 500, nullable: true)]
    protected ?string $webhookUrl = null;

    /**
     * Announces captured contact details on the core contact signal, for a
     * CRM to pick up if one is installed. Editorial never imports it.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $crmSync = false;

    /**
     * Step titles, in order. Null means a single-page form — which is not the
     * same as an empty list, and the difference is what the builder shows.
     *
     * @var list<array{title: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $steps = null;

    /** An inactive form 404s rather than rendering closed: it is a draft. */
    #[ORM\Column]
    protected bool $active = true;

    /** @var Collection<string, FormTranslationInterface> */
    #[ORM\OneToMany(targetEntity: FormTranslationInterface::class, mappedBy: 'form', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    protected Collection $translations;

    /** @var Collection<int, FormFieldInterface> */
    #[ORM\OneToMany(targetEntity: FormFieldInterface::class, mappedBy: 'form', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => Order::Ascending->value])]
    protected Collection $fields;

    /** @var Collection<int, FormSubmissionInterface> */
    #[ORM\OneToMany(targetEntity: FormSubmissionInterface::class, mappedBy: 'form', cascade: ['remove'])]
    protected Collection $submissions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->translations = new ArrayCollection();
        $this->fields = new ArrayCollection();
        $this->submissions = new ArrayCollection();
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

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(?string $notifyEmail): static
    {
        $this->notifyEmail = $notifyEmail;

        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $webhookUrl): static
    {
        $this->webhookUrl = $webhookUrl;

        return $this;
    }

    public function isCrmSync(): bool
    {
        return $this->crmSync;
    }

    public function setCrmSync(bool $crmSync): static
    {
        $this->crmSync = $crmSync;

        return $this;
    }

    public function getSteps(): ?array
    {
        return $this->steps;
    }

    public function setSteps(?array $steps): static
    {
        $this->steps = $steps;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function getTranslation(string $locale): ?FormTranslationInterface
    {
        return $this->translations->get($locale);
    }

    public function translate(string $locale): FormTranslationInterface
    {
        if ($this->translations->containsKey($locale)) {
            return $this->translations->get($locale);
        }

        $translation = $this->createTranslation();
        $translation->setForm($this);
        $translation->setLocale($locale);

        $this->translations->set($locale, $translation);

        return $translation;
    }

    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(FormFieldInterface $field): static
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setForm($this);
        }

        return $this;
    }

    public function removeField(FormFieldInterface $field): static
    {
        $this->fields->removeElement($field);

        return $this;
    }

    public function findFieldById(int $fieldId): ?FormFieldInterface
    {
        foreach ($this->fields as $field) {
            if ($field->getId() === $fieldId) {
                return $field;
            }
        }

        return null;
    }

    public function getSubmissions(): Collection
    {
        return $this->submissions;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Editing a field is editing the form, so the timestamp moves with either.
     * A method rather than a public setter: nothing outside should be able to
     * claim the form changed at a time of its choosing.
     */
    public function touch(): static
    {
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** @see AbstractFormField::createTranslation() */
    protected function createTranslation(): FormTranslationInterface
    {
        return new FormTranslation();
    }
}
