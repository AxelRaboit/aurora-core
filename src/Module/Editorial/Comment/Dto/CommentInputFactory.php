<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Dto;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CommentInputFactoryInterface::class)]
class CommentInputFactory implements CommentInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CommentInputInterface
    {
        $parentId = (int) ($data['parentId'] ?? 0);

        return new CommentInput(
            authorName: mb_trim((string) ($data['authorName'] ?? '')),
            authorEmail: mb_trim((string) ($data['authorEmail'] ?? '')),
            content: mb_trim((string) ($data['content'] ?? '')),
            parentId: $parentId > 0 ? $parentId : null,
            honeypot: mb_trim((string) ($data['website'] ?? '')),
        );
    }
}
