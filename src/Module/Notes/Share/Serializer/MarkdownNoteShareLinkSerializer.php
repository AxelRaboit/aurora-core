<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Serializer;

use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class MarkdownNoteShareLinkSerializer
{
    public function __construct(private UrlGeneratorInterface $urlGenerator) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(MarkdownNoteShareLinkInterface $link): array
    {
        return [
            'id' => $link->getId(),
            // The owner is the one person entitled to see the address again:
            // they made it, and a share screen that cannot show you the link you
            // created is a share screen you cannot use.
            'url' => $this->urlGenerator->generate(
                'notes_share',
                ['token' => $link->getToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'label' => $link->getLabel(),
            'recipientEmail' => $link->getRecipientEmail(),
            'includeDescendants' => $link->includesDescendants(),
            'includeLinked' => $link->includesLinked(),
            'expiresAt' => $link->getExpiresAt()?->format('c'),
            'revokedAt' => $link->getRevokedAt()?->format('c'),
            'sentAt' => $link->getSentAt()?->format('c'),
            'lastUsedAt' => $link->getLastUsedAt()?->format('c'),
            'createdAt' => $link->getCreatedAt()->format('c'),
        ];
    }
}
