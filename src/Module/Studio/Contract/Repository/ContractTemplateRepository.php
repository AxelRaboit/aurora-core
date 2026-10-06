<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<ContractTemplateInterface>
 */
class ContractTemplateRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractTemplate::class, ContractTemplateInterface::class);
    }

    /**
     * Every template with its versions, bodies before annexes.
     *
     * The versions are joined rather than looked up per row: the list shows
     * each template's state (published number, draft open or not), which is
     * one query here and one per template without it.
     *
     * @return list<ContractTemplateInterface>
     */
    public function findAllForIndex(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('v')
            ->leftJoin('t.versions', 'v')
            // Live templates before archived ones, bodies before annexes, then
            // by name: « annex » sorted before « body » alphabetically, and
            // retired templates were mixed with the ones in use.
            ->addSelect('CASE WHEN t.archivedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN archived_rank')
            ->addSelect("CASE WHEN t.kind = 'body' THEN 0 ELSE 1 END AS HIDDEN kind_rank")
            ->orderBy('archived_rank', Order::Ascending->value)
            ->addOrderBy('kind_rank', Order::Ascending->value)
            ->addOrderBy('t.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The templates a contract can be built from today: live, and published.
     *
     * An archived template is out, and so is one that has only ever been a
     * draft - offering either would mean offering a document that cannot be
     * sent.
     *
     * @return list<ContractTemplateInterface>
     */
    public function findSelectable(ContractTemplateKindEnum $kind): array
    {
        /** @var list<ContractTemplateInterface> $templates */
        $templates = $this->createQueryBuilder('t')
            ->innerJoin('t.versions', 'v')
            ->andWhere('t.kind = :kind')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('v.publishedAt IS NOT NULL')
            ->setParameter('kind', $kind)
            ->orderBy('t.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        // The picker reads each template's latest published version and the
        // blanks its wording asks for. The join above filters and cannot
        // carry them - a filtered collection would pass for the whole one -
        // so every version and its translations come in a second query rather
        // than two queries per template.
        if ([] !== $templates) {
            $this->createQueryBuilder('t')
                ->leftJoin('t.versions', 'everyVersion')
                ->leftJoin('everyVersion.translations', 'tr')
                ->addSelect('everyVersion', 'tr')
                ->where('t IN (:templates)')
                ->setParameter('templates', $templates)
                ->getQuery()
                ->getResult();
        }

        return $templates;
    }

    /**
     * The templates whose name contains the term, with their versions.
     *
     * In two steps: the limit applies to the templates, and a join on a
     * collection would make it apply to the rows (a template with three
     * versions would count as three). The versions then come in one query,
     * because the result row opens the current version or the draft, and
     * fetching them template by template would cost one query each. Archived
     * ones come after the others.
     *
     * @return list<ContractTemplateInterface>
     */
    public function searchByName(string $term, int $limit): array
    {
        if ('' === mb_trim($term)) {
            return [];
        }

        $ids = $this->createQueryBuilder('t')
            ->select('t.id')
            ->addSelect('CASE WHEN t.archivedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN archivedRank')
            ->where('LOWER(t.name) LIKE :term')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('archivedRank', Order::Ascending->value)
            ->addOrderBy('t.name', Order::Ascending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        /** @var list<ContractTemplateInterface> $templates */
        $templates = $this->createQueryBuilder('t')
            ->addSelect('v')
            ->leftJoin('t.versions', 'v')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Back in the order the first query chose.
        $position = array_flip(array_map(intval(...), $ids));
        usort(
            $templates,
            static fn (ContractTemplateInterface $a, ContractTemplateInterface $b): int => $position[(int) $a->getId()] <=> $position[(int) $b->getId()],
        );

        return $templates;
    }
}
