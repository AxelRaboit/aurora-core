<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractFormField implements FormFieldInterface
{
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: FormInterface::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected FormInterface $form;

    #[ORM\Column(length: 50, enumType: FormFieldTypeEnum::class)]
    protected FormFieldTypeEnum $type = FormFieldTypeEnum::Text;

    #[ORM\Column]
    protected bool $required = false;

    #[ORM\Column]
    protected int $position = 0;

    /**
     * Show this field only when the referenced fields hold these values.
     * Empty means always shown, which is why it is a list rather than null:
     * "no conditions" and "conditions nobody filled in" are the same thing
     * to a reader, and treating them differently only creates a way to lose
     * a field.
     *
     * @var list<array{fieldId: int, value: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $conditions = [];

    #[ORM\Column(length: 3, enumType: ConditionLogicEnum::class, options: ['default' => 'and'])]
    protected ConditionLogicEnum $conditionsLogic = ConditionLogicEnum::All;

    /** Which step of a multi-step form holds this field; null on a single-page one. */
    #[ORM\Column(nullable: true)]
    protected ?int $step = null;

    /** @var Collection<string, FormFieldTranslationInterface> */
    #[ORM\OneToMany(targetEntity: FormFieldTranslationInterface::class, mappedBy: 'field', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    protected Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
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

    public function getType(): FormFieldTypeEnum
    {
        return $this->type;
    }

    public function setType(FormFieldTypeEnum $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function setConditions(array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function getConditionsLogic(): ConditionLogicEnum
    {
        return $this->conditionsLogic;
    }

    public function setConditionsLogic(ConditionLogicEnum $logic): static
    {
        $this->conditionsLogic = $logic;

        return $this;
    }

    public function getStep(): ?int
    {
        return $this->step;
    }

    public function setStep(?int $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function getTranslation(string $locale): ?FormFieldTranslationInterface
    {
        return $this->translations->get($locale);
    }

    public function translate(string $locale): FormFieldTranslationInterface
    {
        if ($this->translations->containsKey($locale)) {
            return $this->translations->get($locale);
        }

        $translation = $this->createTranslation();
        $translation->setField($this);
        $translation->setLocale($locale);

        $this->translations->set($locale, $translation);

        return $translation;
    }

    /**
     * Instantiation hook, so a client substituting the translation class gets
     * its own here too - resolve_target_entities only covers Doctrine
     * associations, never a `new` in application code.
     */
    protected function createTranslation(): FormFieldTranslationInterface
    {
        return new FormFieldTranslation();
    }
}
