<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Module\Editorial\Post\Service\PostPictures;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckPictures;

use function array_unique;
use function array_values;
use function sprintf;

/**
 * What would go wrong if a deliverable reached its reader as it stands.
 *
 * Two things the author cannot see from the editor: a [passage à remplacer]
 * still in the text, and a picture of the library that is not published, which
 * a reader outside the back-office will see as a broken image. Asked at both
 * moments a document leaves the studio - opening it to a client, and giving
 * out an address - so the warning is the same wherever it is raised.
 */
final readonly class DeliverableReadiness
{
    public function __construct(
        private PostPictures $pictures,
        private DocumentRepository $documentRepository,
        private DeliverablePlaceholders $placeholders,
        private DeckPictures $deckPictures,
    ) {}

    /**
     * A slideshow has no grid: its images are those of its slides and its
     * logo, see `DeckPictures`, and its [passages to replace] are not
     * counted.
     *
     * @return array{placeholders: int, withheldPictures: list<array{id: int, name: string}>}
     */
    public function report(DeliverableInterface $deliverable): array
    {
        return [
            'placeholders' => $deliverable->isSlides() ? 0 : $this->placeholders->count($deliverable),
            'withheldPictures' => $this->withheldPictures($deliverable),
        ];
    }

    /**
     * The media library documents the page uses without them being published:
     * a reader outside the back office will not see them, and it is better to
     * say so before sending the address. The deliverable's image counts too:
     * the client sees it on their space's card.
     *
     * @return list<array{id: int, name: string}>
     */
    public function withheldPictures(DeliverableInterface $deliverable): array
    {
        // Only the zones the page shows: an image in a zone the client will
        // not see has no reason to hold it back.
        $layout = DeliverablePageRenderer::withoutHiddenLayoutZones($deliverable->getGridLayout());
        $ids = $deliverable->isSlides() ? $this->deckPictures->idsUsedBy($deliverable) : $this->pictures->idsInGridLayout($layout);

        $thumbnail = $deliverable->getThumbnail()?->getId();
        if (null !== $thumbnail) {
            $ids[] = $thumbnail;
        }

        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return [];
        }

        $withheld = [];
        foreach ($this->documentRepository->findBy(['id' => $ids]) as $document) {
            if (DocumentStatusEnum::Published === $document->getStatus()) {
                continue;
            }

            $withheld[] = [
                'id' => (int) $document->getId(),
                'name' => $document->getOriginalName() ?? sprintf('#%d', $document->getId()),
            ];
        }

        return $withheld;
    }
}
