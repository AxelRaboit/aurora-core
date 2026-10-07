<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\View;

use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadiness;

use function array_map;

/**
 * What a deliverable's reading links dialog receives, whether the deliverable
 * lives in a space or in Studio.
 */
final readonly class DeliverableLinksView
{
    public function __construct(
        private DeliverableLinkRepository $links,
        private DeliverableSerializer $serializer,
        private DeliverableReadiness $readiness,
    ) {}

    /**
     * A deliverable's reading links, and the images their readers will not
     * see.
     *
     * @return array<string, mixed>
     */
    public function payload(DeliverableInterface $deliverable): array
    {
        return [
            // Nothing is a draft here: a link always opens the page.
            'readable' => true,
            // The unpublished images and the [passages to replace] that would
            // go out with the address.
            ...$this->readiness->report($deliverable),
            'links' => array_map($this->serializer->link(...), $this->links->findForDeliverable($deliverable)),
        ];
    }
}
