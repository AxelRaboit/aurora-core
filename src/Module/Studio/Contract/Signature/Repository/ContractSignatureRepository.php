<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Signature\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureInterface;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<ContractSignatureInterface>
 */
class ContractSignatureRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractSignature::class, ContractSignatureInterface::class);
    }

    /**
     * Both signatures of a contract, in the order they were given.
     *
     * @return list<ContractSignatureInterface>
     */
    public function findForContract(ContractInterface $contract): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.contract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('s.signedAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    public function findOneForRole(ContractInterface $contract, ContractSignatureRoleEnum $role): ?ContractSignatureInterface
    {
        return $this->findOneBy(['contract' => $contract, 'role' => $role]);
    }

    /**
     * Every signature whose document has moved under it.
     *
     * The query the verification command needs on top of the hashes: a
     * contract can verify against its own seal and still carry a signature
     * taken over a different hash, if somebody re-sealed it. Comparing the two
     * columns is what catches that.
     *
     * @return list<ContractSignatureInterface>
     */
    public function findWithDivergedHash(): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c')
            ->innerJoin('s.contract', 'c')
            ->andWhere('s.signedContentHash != c.contentHash')
            ->orderBy('s.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * When each contract was last signed, by contract id.
     *
     * @param list<ContractInterface> $contracts
     *
     * @return array<int, DateTimeImmutable>
     */
    public function latestSignedAtForContracts(array $contracts): array
    {
        if ([] === $contracts) {
            return [];
        }

        /** @var list<array{id: int|string, signed: ?string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.contract) AS id', 'MAX(s.signedAt) AS signed')
            ->andWhere('s.contract IN (:contracts)')
            ->setParameter('contracts', $contracts)
            ->groupBy('s.contract')
            ->getQuery()
            ->getArrayResult();

        $latest = [];
        foreach ($rows as $row) {
            if (null !== $row['signed']) {
                $latest[(int) $row['id']] = new DateTimeImmutable($row['signed']);
            }
        }

        return $latest;
    }
}
