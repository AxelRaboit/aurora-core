<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run of grid zones kept to be inserted again: « mon bloc SWOT », « ma
 * page de contact ». Saved from a publication or a deliverable, offered back
 * in both, beside the sections the library ships with.
 *
 * Its author's own, like a draft: nobody else sees it. The zones and their
 * words are stored as the editor copies them - the layout of the zones and
 * what each holds - and pass through the grid normaliser on the way in, so a
 * section can never carry more than a grid could.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractGridSection implements GridSectionInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 120)]
    protected string $name = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?CoreUserInterface $owner = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    protected array $layout = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    protected array $content = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getOwner(): ?CoreUserInterface
    {
        return $this->owner;
    }

    public function setOwner(?CoreUserInterface $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getLayout(): array
    {
        return $this->layout;
    }

    public function setLayout(array $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    public function getContent(): array
    {
        return $this->content;
    }

    public function setContent(array $content): static
    {
        $this->content = $content;

        return $this;
    }
}
