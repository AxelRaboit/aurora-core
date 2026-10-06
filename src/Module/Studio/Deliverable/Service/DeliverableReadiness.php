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
        private DocumentRepository $documents,
        private DeliverablePlaceholders $placeholders,
        private DeckPictures $deckPictures,
    ) {}

    /**
     * Un diaporama n'a pas de grille : ses images sont celles de ses
     * diapositives et de son logo, cf. `DeckPictures`, et ses
     * [passages à remplacer] ne sont pas comptés.
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
     * Les documents de la médiathèque que la page utilise sans qu'ils soient
     * publiés : un lecteur hors du back-office ne les verra pas, et mieux vaut
     * le dire avant d'envoyer l'adresse. L'image du livrable compte aussi : le
     * client la voit sur la carte de son espace.
     *
     * @return list<array{id: int, name: string}>
     */
    public function withheldPictures(DeliverableInterface $deliverable): array
    {
        // Seules les zones que la page montre : une image dans une zone que le
        // client ne verra pas n'a pas à le retarder.
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
        foreach ($this->documents->findBy(['id' => $ids]) as $document) {
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
