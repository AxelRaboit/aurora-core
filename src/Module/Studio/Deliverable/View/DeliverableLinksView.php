<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\View;

use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadiness;

use function array_map;

/**
 * Ce que reçoit la fenêtre des liens de lecture d'un livrable, qu'il vive dans
 * un espace ou dans Studio.
 */
final readonly class DeliverableLinksView
{
    public function __construct(
        private DeliverableLinkRepository $links,
        private DeliverableSerializer $serializer,
        private DeliverableReadiness $readiness,
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
            // Les images non publiées et les [passages à remplacer] qui
            // partiraient avec l'adresse.
            ...$this->readiness->report($deliverable),
            'links' => array_map($this->serializer->link(...), $this->links->findForDeliverable($deliverable)),
        ];
    }
}
