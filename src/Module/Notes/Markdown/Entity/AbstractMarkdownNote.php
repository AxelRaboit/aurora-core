<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note is private to the person who wrote it.
 *
 * Title and body are stored through {@see EncryptedTextType}: notes are a
 * scratchpad, and people write things there they would not put in a document
 * they know is shared. That choice has a cost worth knowing - an encrypted
 * column cannot be searched or sorted in SQL, so title search and tag filtering
 * happen in PHP over the user's own notes rather than in the query.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractMarkdownNote implements MarkdownNoteInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?MarkdownNoteInterface $parent = null;

    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $content = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    protected array $tags = [];

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    protected int $position = 0;

    public function getUser(): CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getParent(): ?MarkdownNoteInterface
    {
        return $this->parent;
    }

    public function setParent(?MarkdownNoteInterface $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): static
    {
        $this->tags = $tags;

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
}
