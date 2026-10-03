<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Serializer;

use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLinkInterface;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadingHeader;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Les trois formes d'un livrable : une ligne de liste, l'éditeur, un lien de lecture. */
final readonly class DeliverableSerializer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function row(DeliverableInterface $deliverable): array
    {
        $params = ['id' => $deliverable->getSpace()->getId(), 'deliverableId' => $deliverable->getId()];

        return [
            'id' => $deliverable->getId(),
            'title' => $deliverable->getTitle(),
            'summary' => $deliverable->getSummary(),
            'visibleToClient' => $deliverable->isVisibleToClient(),
            'updatedAt' => $deliverable->getUpdatedAt()->format(DATE_ATOM),
            'editPath' => $this->urlGenerator->generate('workspace_space_deliverables_edit', $params),
            'previewPath' => $this->urlGenerator->generate('workspace_space_deliverables_preview', $params),
        ];
    }

    /** @return array<string, mixed> */
    public function editor(DeliverableInterface $deliverable): array
    {
        return [
            'id' => $deliverable->getId(),
            'title' => $deliverable->getTitle(),
            'summary' => $deliverable->getSummary() ?? '',
            'locale' => $deliverable->getLocale(),
            'gridLayout' => $deliverable->getGridLayout(),
            'gridContent' => $deliverable->getGridContent(),
            'appearance' => DeliverableAppearance::normalize($deliverable->getAppearance()),
            'readingHeader' => DeliverableReadingHeader::normalize($deliverable->getReadingHeader()),
            'visibleToClient' => $deliverable->isVisibleToClient(),
            'updatedAt' => $deliverable->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function link(DeliverableLinkInterface $link): array
    {
        return [
            'id' => $link->getId(),
            'label' => $link->getLabel(),
            'url' => $this->urlGenerator->generate(
                'public_deliverable_read',
                ['token' => $link->getToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'expiresAt' => $link->getExpiresAt()?->format(DATE_ATOM),
            'revokedAt' => $link->getRevokedAt()?->format(DATE_ATOM),
            'lastUsedAt' => $link->getLastUsedAt()?->format(DATE_ATOM),
            'openCount' => $link->getOpenCount(),
            'locked' => $link->isLocked(),
            'createdAt' => $link->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
