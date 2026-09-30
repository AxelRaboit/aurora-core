<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Integrity;

use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Service\ContractPdfGenerator;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;
use Throwable;

use function count;
use function hash_final;
use function hash_init;
use function hash_update;
use function sprintf;

/**
 * Checks that what was sealed is still what is stored.
 *
 * Three things can drift, and each has its own witness:
 *
 * - the document, against the hash it was sealed with;
 * - a signature, against the hash of the document it was given on (a
 *   contract re-sealed after a signature verifies against its new seal and
 *   still carries a signature over another text);
 * - the countersigned PDF, against the hash written when it was generated:
 *   the file is the copy both parties keep, and a replaced or vanished file
 *   was invisible until somebody downloaded it.
 *
 * Reads and never writes.
 */
final readonly class ContractIntegrityChecker
{
    public function __construct(
        private ContractRepository $contracts,
        private ContractSignatureRepository $signatures,
        private ContractSeal $seal,
        private ContractPdfGenerator $pdf,
    ) {}

    public function check(): ContractIntegrityReport
    {
        $frozen = $this->contracts->findFrozen();
        $altered = [];
        $unverifiable = [];

        foreach ($frozen as $contract) {
            try {
                if (!$this->seal->verify($contract)) {
                    $altered[] = sprintf('%s: the document no longer matches its seal', $this->describe($contract));
                }
            } catch (Throwable $throwable) {
                // A contract sealed under a canonical form this code no longer
                // implements is not evidence of tampering, and reporting it as
                // such would be the fastest way to make this check
                // untrustworthy. It is listed apart.
                $unverifiable[] = sprintf('%s: %s', $this->describe($contract), $throwable->getMessage());
            }

            $pdfProblem = $this->pdfProblem($contract);
            if (null !== $pdfProblem) {
                $altered[] = sprintf('%s: %s', $this->describe($contract), $pdfProblem);
            }
        }

        foreach ($this->signatures->findWithDivergedHash() as $signature) {
            $altered[] = sprintf(
                '%s: the %s signature was given on another version of the document',
                $this->describe($signature->getContract()),
                $signature->getRole()->value,
            );
        }

        return new ContractIntegrityReport(count($frozen), $altered, $unverifiable);
    }

    /** What is wrong with the stored PDF, or null when nothing is. */
    private function pdfProblem(ContractInterface $contract): ?string
    {
        $expected = $contract->getPdfHash();
        if (null === $expected) {
            return null;
        }

        try {
            if (!$this->pdf->exists($contract)) {
                return 'the signed PDF is missing from the storage';
            }

            $context = hash_init('sha256');
            foreach ($this->pdf->readStream($contract) as $chunk) {
                hash_update($context, $chunk);
            }
        } catch (Throwable $throwable) {
            return sprintf('the signed PDF could not be read (%s)', $throwable->getMessage());
        }

        return hash_final($context) === $expected ? null : 'the signed PDF no longer matches the hash taken when it was generated';
    }

    private function describe(ContractInterface $contract): string
    {
        return sprintf(
            '%s (id %d, %s)',
            $contract->getReference() ?? 'no reference',
            (int) $contract->getId(),
            $contract->getCustomer()->getLegalName(),
        );
    }
}
