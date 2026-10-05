<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Core\Twig\PlaceholderCounter;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;

/**
 * The blanks a deliverable still holds, wherever it keeps text: the grid's
 * content, but also its title, its summary and its reading header.
 *
 * The editor counted the grid only, so a « [Nom du client] » left in the
 * title, or « Préparé pour » still reading « [Client] », went out unwarned.
 * Asked by the list before it opens a deliverable to a client, and by the
 * editor's own badge through the same definition on the client side.
 */
final readonly class DeliverablePlaceholders
{
    public function __construct(private PlaceholderCounter $counter) {}

    public function count(DeliverableInterface $deliverable): int
    {
        $content = DeliverablePageRenderer::contentOfShownZones(
            DeliverablePageRenderer::withoutHiddenLayoutZones($deliverable->getGridLayout()),
            $deliverable->getGridContent(),
        );

        return $this->counter->count([
            $deliverable->getTitle(),
            $deliverable->getSummary(),
            $deliverable->getReadingHeader(),
            $content['zones'] ?? [],
        ]);
    }
}
