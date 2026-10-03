<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\View;

use Aurora\Module\Editorial\Post\Service\PostPictures;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;

use function array_map;
use function sprintf;

/**
 * Ce que reçoit la fenêtre des liens de lecture d'un livrable, qu'il vive dans
 * un espace ou dans Studio.
 */
final readonly class DeliverableLinksView
{
    public function __construct(
        private DeliverableLinkRepository $links,
        private DeliverableSerializer $serializer,
        private PostPictures $pictures,
        private DocumentRepository $documents,
    ) {}

    /**
     * Les liens de lecture d'un livrable, et les images que leurs lecteurs ne
     * verront pas.
     *
     * @return array<string, mixed>
     */
    public function payload(DeliverableInterface $deliverable): array
    {
        return [
            // Rien n'est un brouillon ici : un lien ouvre toujours la page.
            'readable' => true,
            'withheldPictures' => $this->withheldPictures($deliverable),
            'links' => array_map($this->serializer->link(...), $this->links->findForDeliverable($deliverable)),
        ];
    }

    /**
     * Les documents de la médiathèque que la page utilise sans qu'ils soient
     * publiés : un lecteur hors du back-office ne les verra pas, et mieux vaut
     * le dire avant d'envoyer l'adresse.
     *
     * @return list<array{id: int, name: string}>
     */
    private function withheldPictures(DeliverableInterface $deliverable): array
    {
        $ids = $this->pictures->idsInGridLayout($deliverable->getGridLayout());
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
