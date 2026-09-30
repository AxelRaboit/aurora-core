<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Integrity;

/**
 * What a pass over the sealed contracts found.
 *
 * `altered` is evidence: a document, a signature or a PDF that no longer
 * matches what was sealed. `unverifiable` is not: a contract this version of
 * the code cannot check, kept apart so the first list stays trustworthy.
 */
final readonly class ContractIntegrityReport
{
    /**
     * @param list<string> $altered
     * @param list<string> $unverifiable
     */
    public function __construct(
        public int $checked,
        public array $altered,
        public array $unverifiable,
    ) {}

    public function isClean(): bool
    {
        return [] === $this->altered && [] === $this->unverifiable;
    }
}
