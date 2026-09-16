<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Orphan;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Orphan\ReferencedKeysProviderInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;

/**
 * The sealed PDF each contract still points at.
 *
 * A contract's PDF is generated once and never replaced - the entity refuses a
 * second one outright - so an orphan here is the trace of a contract that was
 * deleted, and nothing else. Few, and worth naming rather than leaving to
 * accumulate unseen next to the ones that matter.
 */
final readonly class ContractReferencedKeysProvider implements ReferencedKeysProviderInterface
{
    public function __construct(private ContractRepository $contractRepository) {}

    public function area(): StorageAreaEnum
    {
        return StorageAreaEnum::Contracts;
    }

    public function referencedKeys(): iterable
    {
        /** @var list<array{pdfPath: string|null}> $rows */
        $rows = $this->contractRepository->createQueryBuilder('c')
            ->select('c.pdfPath')
            ->where('c.pdfPath IS NOT NULL')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            if (null !== $row['pdfPath'] && '' !== $row['pdfPath']) {
                yield $row['pdfPath'];
            }
        }
    }
}
