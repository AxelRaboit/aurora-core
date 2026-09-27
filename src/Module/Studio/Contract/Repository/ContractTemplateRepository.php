<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
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
            ->orderBy('t.kind', Order::Ascending->value)
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
}
