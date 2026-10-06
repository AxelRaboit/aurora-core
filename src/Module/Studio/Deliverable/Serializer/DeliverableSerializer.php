<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Serializer;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategoryInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLinkInterface;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadingHeader;
use DateTimeInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Les trois formes d'un livrable : une ligne de liste, l'éditeur, un lien de lecture. */
final readonly class DeliverableSerializer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private DocumentUrlGenerator $documentUrls,
    ) {}

    /** @return array<string, mixed> */
    public function row(DeliverableInterface $deliverable): array
    {
        return [
            'id' => $deliverable->getId(),
            'title' => $deliverable->getTitle(),
            'summary' => $deliverable->getSummary(),
            'format' => $deliverable->getFormat()->value,
            'template' => $deliverable->isTemplate(),
            'customer' => $this->customer($deliverable->getCustomer()),
            'visibleToClient' => $deliverable->isVisibleToClient(),
            'scope' => $deliverable->isStandalone() ? $deliverable->getScope()->value : null,
            'category' => $deliverable->isStandalone() ? $this->category($deliverable->getCategory()) : null,
            'ownerName' => $deliverable->getOwner()?->getName(),
            // La vignette en taille réduite, cadrée sur le point d'intérêt du document.
            'thumbnailUrl' => $this->documentUrls->thumbUrl($deliverable->getThumbnail()),
            'thumbnailPosition' => $this->documentUrls->focalPositionCss($deliverable->getThumbnail()),
            'updatedAt' => $deliverable->getUpdatedAt()->format(DATE_ATOM),
            'editPath' => $this->path($deliverable, 'edit'),
            'previewPath' => $this->path($deliverable, 'preview'),
        ];
    }

    /**
     * La ligne d'un livrable d'espace, depuis les colonnes de la liste : le
     * même dessin que {@see self::row()}, sans avoir lu le corps du livrable.
     * Un livrable d'espace n'a ni rayon, ni catégorie, ni propriétaire à
     * montrer, et il n'est jamais un modèle.
     *
     * @param array{id: int, title: string, summary: ?string, format: string, visibleToClient: bool, updatedAt: DateTimeInterface, thumbnailId: ?int} $row
     *
     * @return array<string, mixed>
     */
    public function spaceRow(array $row, CustomerSpaceInterface $space, ?DocumentInterface $thumbnail): array
    {
        $params = ['id' => $space->getId(), 'deliverableId' => $row['id']];

        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'summary' => $row['summary'],
            'format' => $row['format'],
            'template' => false,
            'customer' => null,
            'visibleToClient' => $row['visibleToClient'],
            'scope' => null,
            'category' => null,
            'ownerName' => null,
            'thumbnailUrl' => $this->documentUrls->thumbUrl($thumbnail),
            'thumbnailPosition' => $this->documentUrls->focalPositionCss($thumbnail),
            'updatedAt' => $row['updatedAt']->format(DATE_ATOM),
            'editPath' => $this->urlGenerator->generate('workspace_space_deliverables_edit', $params),
            'previewPath' => $this->urlGenerator->generate('workspace_space_deliverables_preview', $params),
        ];
    }

    /**
     * L'adresse d'un geste sur un livrable, là où il vit : dans son espace,
     * ou dans le module Livrables de Studio.
     */
    public function path(DeliverableInterface $deliverable, string $action): string
    {
        $space = $deliverable->getSpace();

        return $space instanceof CustomerSpaceInterface
            ? $this->urlGenerator->generate('workspace_space_deliverables_'.$action, ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()])
            : $this->urlGenerator->generate('suite_studio_deliverables_'.$action, ['id' => $deliverable->getId()]);
    }

    /** @return array<string, mixed> */
    public function editor(DeliverableInterface $deliverable): array
    {
        return [
            'id' => $deliverable->getId(),
            'title' => $deliverable->getTitle(),
            'summary' => $deliverable->getSummary() ?? '',
            'locale' => $deliverable->getLocale(),
            'format' => $deliverable->getFormat()->value,
            'template' => $deliverable->isTemplate(),
            'customerId' => $deliverable->getCustomer()?->getId(),
            'gridLayout' => $deliverable->getGridLayout(),
            'gridContent' => $deliverable->getGridContent(),
            'appearance' => DeliverableAppearance::normalize($deliverable->getAppearance()),
            'readingHeader' => DeliverableReadingHeader::normalize($deliverable->getReadingHeader()),
            'visibleToClient' => $deliverable->isVisibleToClient(),
            'scope' => $deliverable->isStandalone() ? $deliverable->getScope()->value : null,
            'categoryId' => $deliverable->isStandalone() ? $deliverable->getCategory()?->getId() : null,
            'thumbnail' => [
                'id' => $deliverable->getThumbnail()?->getId(),
                'url' => $this->documentUrls->thumbUrl($deliverable->getThumbnail()),
            ],
            'updatedAt' => $deliverable->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array{id: int|null, name: string, color: string|null, position: int}|null */
    public function category(?DeliverableCategoryInterface $category): ?array
    {
        if (!$category instanceof DeliverableCategoryInterface) {
            return null;
        }

        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'color' => $category->getColor(),
            'position' => $category->getPosition(),
        ];
    }

    /** @return array{id: int|null, legalName: string}|null */
    public function customer(?CustomerInterface $customer): ?array
    {
        if (!$customer instanceof CustomerInterface) {
            return null;
        }

        return [
            'id' => $customer->getId(),
            'legalName' => $customer->getLegalName(),
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
            'hidden' => $link->isHidden(),
            'lastUsedAt' => $link->getLastUsedAt()?->format(DATE_ATOM),
            'openCount' => $link->getOpenCount(),
            'locked' => $link->isLocked(),
            'createdAt' => $link->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
