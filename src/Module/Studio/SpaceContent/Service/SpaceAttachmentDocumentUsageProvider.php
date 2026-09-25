<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Service;

use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Says which client spaces carry a given file, before somebody deletes it.
 *
 * **The most expensive silence of the three consumers.** A space attachment
 * holds its document with `onDelete: CASCADE`, so deleting the file from the
 * library does not merely blank a picture - it removes the attachment row,
 * and the file disappears from the client's space with nothing left behind
 * to say it was ever there. Until this provider existed the deletion screen
 * answered "no usage" for exactly that document.
 *
 * Joined rather than scanned, unlike {@see DeckDocumentUsageProvider}: the
 * relation is a typed FK, so the query is exact and survives a rename.
 */
final readonly class SpaceAttachmentDocumentUsageProvider implements BatchDocumentUsageProviderInterface
{
    public function __construct(
        private SpaceContentAttachmentRepository $attachments,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $usages = [];

        foreach ($this->attachments->findUsingDocument($documentId) as $attachment) {
            $item = $attachment->getItem();

            $usages[] = [
                'type' => 'studio.space_attachment',
                'label' => $item->getTitle(),
                // The card alone is not enough to place the file: card titles
                // repeat across spaces ("Devis", "Photos"), and what the
                // person deleting needs to know is whose space it is.
                'detail' => $this->translator->trans(
                    'backend.studio.spaces.usage_detail',
                    ['{space}' => $item->getSpace()->getName()],
                ),
                'href' => $this->urlGenerator->generate(
                    'workspace_space_content',
                    ['id' => $item->getSpace()->getId()],
                ),
            ];
        }

        return $usages;
    }

    /**
     * The same answer for a page of documents, grouped in one query.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesFor(array $documentIds): array
    {
        return $this->attachments->countByDocument($documentIds);
    }
}
