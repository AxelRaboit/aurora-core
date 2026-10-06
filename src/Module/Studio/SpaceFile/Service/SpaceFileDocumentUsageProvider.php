<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Service;

use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Aurora\Module\Ged\Document\Contract\TypedDocumentUsageProviderInterface;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentDocumentUsageProvider;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Says which spaces carry a file, before it is deleted.
 *
 * The same costly silence {@see SpaceAttachmentDocumentUsageProvider} avoided
 * for records, and for the same reason: the row cascades, so deleting the
 * document does not blank a thumbnail, it removes the file from the space
 * without leaving anything behind.
 */
final readonly class SpaceFileDocumentUsageProvider implements BatchDocumentUsageProviderInterface, TypedDocumentUsageProviderInterface
{
    public function __construct(
        private SpaceFileRepository $files,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    public function usageType(): string
    {
        return 'studio.space_file';
    }

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $usages = [];

        foreach ($this->files->findUsingDocument($documentId) as $file) {
            $space = $file->getSpace();

            $usages[] = [
                'type' => $this->usageType(),
                // The space's name, not the document's: the delete screen
                // already says which file goes, and what is worth knowing
                // before confirming is where it is used.
                'label' => $space->getName(),
                // A space in the trash keeps its files for its restoration:
                // this says so, without a link to an address that no longer
                // answers.
                'detail' => $this->translator->trans($space->isTrashed() ? 'suite.studio.space_files.usage_detail_trashed' : 'suite.studio.space_files.usage_detail'),
                'href' => $space->isTrashed() ? null : $this->urlGenerator->generate(
                    'workspace_space_content',
                    ['id' => $space->getId()],
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
        return $this->files->countByDocument($documentIds);
    }
}
