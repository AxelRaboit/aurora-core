<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Manager;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use DateTimeImmutable;

interface MarkdownNoteShareLinkManagerInterface
{
    public function create(
        MarkdownNoteInterface $note,
        bool $includeDescendants,
        bool $includeLinked,
        ?string $recipientEmail = null,
        string $label = '',
        ?DateTimeImmutable $expiresAt = null,
    ): MarkdownNoteShareLinkInterface;

    public function revoke(MarkdownNoteShareLinkInterface $link, ?DateTimeImmutable $at = null): void;

    public function resolveUsable(string $token, ?DateTimeImmutable $now = null): ?MarkdownNoteShareLinkInterface;
}
