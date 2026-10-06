<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Module\Editorial\Post\Service\PostPictures;
use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Aurora\Module\Ged\Document\Contract\TypedDocumentUsageProviderInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckPictures;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_fill_keys;
use function in_array;

/**
 * Says which deliverables draw a given picture, before somebody deletes it.
 *
 * A deliverable keeps its pictures two ways: a typed relation for its
 * thumbnail, which the database nulls out when the document goes, and ids
 * buried in the grid's JSON, which nothing nulls out - the zone simply draws
 * nothing. Until this provider existed the library called such a picture
 * "Inutilisé", and its trash purged it on schedule: the thumbnail vanished
 * quietly and a client opened a document with holes in it.
 *
 * **Scanned rather than joined**: the ids live in JSON, the grid's for a page
 * and the slides' for a presentation. {@see PostPictures} and `DeckPictures`
 * are the one list of slots that count, so a new picture slot is covered here
 * the day it is declared there.
 *
 * **A trashed deliverable still counts**: its pictures are not released until
 * the purge, and a restore must find them. It is listed as being in the trash.
 *
 * **A personal deliverable is counted but not named** to someone who cannot
 * open it. The usage list is shown to anyone who manages the library, and a
 * title is exactly what the personal shelf exists to keep private; the count
 * is what stops the deletion, and that stays exact.
 */
final readonly class DeliverableDocumentUsageProvider implements BatchDocumentUsageProviderInterface, TypedDocumentUsageProviderInterface
{
    public function __construct(
        private DeliverableRepository $deliverables,
        private PostPictures $pictures,
        private DeliverableAccess $access,
        private DeliverableSerializer $serializer,
        private TranslatorInterface $translator,
        private DeckPictures $deckPictures,
    ) {}

    public function usageType(): string
    {
        return 'studio.deliverable';
    }

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $usages = [];

        foreach ($this->deliverables->findAllForUsage() as $deliverable) {
            if (!in_array($documentId, $this->idsUsedBy($deliverable), true)) {
                continue;
            }

            $usages[] = $this->usage($deliverable);
        }

        return $usages;
    }

    /**
     * The same walk, run once for a whole page of documents.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesFor(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }

        $wanted = array_fill_keys($documentIds, true);
        $counts = [];

        foreach ($this->deliverables->findAllForUsage() as $deliverable) {
            foreach ($this->idsUsedBy($deliverable) as $documentId) {
                if (isset($wanted[$documentId])) {
                    $counts[$documentId] = ($counts[$documentId] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * La grille d'une page, ou les diapositives et le logo d'un diaporama,
     * cf. {@see DeckPictures} : la seule liste des cases qui comptent.
     *
     * @return list<int>
     */
    private function idsUsedBy(DeliverableInterface $deliverable): array
    {
        $ids = $deliverable->isSlides()
            ? $this->deckPictures->idsUsedBy($deliverable)
            : $this->pictures->idsInGridLayout($deliverable->getGridLayout());

        $thumbnail = $deliverable->getThumbnail()?->getId();
        if (null !== $thumbnail) {
            $ids[] = $thumbnail;
        }

        return $ids;
    }

    /** @return array{type: string, label: string, detail: string, href: ?string} */
    private function usage(DeliverableInterface $deliverable): array
    {
        $space = $deliverable->getSpace();
        $readable = $this->access->canRead($deliverable);
        // À la corbeille, il compte encore (la purge n'a pas eu lieu, la
        // restauration est possible) : il ne s'ouvre plus, il se retrouve là.
        $trashed = $deliverable->isTrashed();

        return [
            'type' => $this->usageType(),
            'label' => $readable ? $deliverable->getTitle() : $this->translator->trans('suite.studio.deliverables.usage_private'),
            'detail' => $trashed
                ? $this->translator->trans('suite.studio.deliverables.usage_detail_trashed')
                : ($space instanceof CustomerSpaceInterface
                    ? $this->translator->trans('suite.studio.deliverables.usage_detail_space', ['%space%' => $space->getName()])
                    : $this->translator->trans('suite.studio.deliverables.usage_detail')),
            'href' => $readable && !$trashed ? $this->serializer->path($deliverable, 'edit') : null,
        ];
    }
}
