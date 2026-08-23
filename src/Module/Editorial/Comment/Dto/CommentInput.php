<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A comment as the public form sends it.
 *
 * The reference validated these fields through a hand-written service that
 * ran the same constraints one at a time. A DTO with attributes is how every
 * other input in Aurora is checked, and the shape the frontend already knows
 * how to read errors from - one fewer way of doing the same thing.
 *
 * Messages are fully-qualified keys. The validator hands them back
 * untranslated and the Vue side looks them up, so a bare `comment.errors.*`
 * renders to a reader as exactly that string.
 */
class CommentInput implements CommentInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'frontend.editorial.comments.errors.name_required')]
        #[Assert\Length(max: 100, maxMessage: 'frontend.editorial.comments.errors.name_too_long')]
        public readonly string $authorName = '',
        #[Assert\NotBlank(message: 'frontend.editorial.comments.errors.email_invalid')]
        #[Assert\Email(message: 'frontend.editorial.comments.errors.email_invalid')]
        #[Assert\Length(max: 180, maxMessage: 'frontend.editorial.comments.errors.email_invalid')]
        public readonly string $authorEmail = '',
        #[Assert\NotBlank(message: 'frontend.editorial.comments.errors.content_required')]
        #[Assert\Length(max: 2000, maxMessage: 'frontend.editorial.comments.errors.content_too_long')]
        public readonly string $content = '',
        public readonly ?int $parentId = null,
        /**
         * A field the form hides and a reader therefore never fills. Bots fill
         * every input they find, so anything here means the submission did not
         * come from the page. Checked in the Manager rather than as a
         * constraint: telling the sender their honeypot tripped is telling
         * them how to get past it next time.
         */
        public readonly string $honeypot = '',
    ) {}

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getAuthorEmail(): string
    {
        return $this->authorEmail;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getHoneypot(): string
    {
        return $this->honeypot;
    }
}
