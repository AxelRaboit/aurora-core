<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Studio\Contract\Dto\ContractInputInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Exception\FrozenContractIsImmutableException;
use Aurora\Module\Studio\Contract\Termination\Dto\ContractTerminationInputInterface;

interface ContractManagerInterface
{
    /** @throws FieldException when a choice names a row that cannot be used */
    public function create(ContractInputInterface $input): ContractInterface;

    /**
     * @throws FrozenContractIsImmutableException
     * @throws FieldException
     */
    public function update(ContractInterface $contract, ContractInputInterface $input): void;

    /**
     * Deletes a draft freely, and a sealed contract only once its retention
     * has run out.
     *
     * @throws FieldException when the retention still covers the document
     */
    public function delete(ContractInterface $contract): void;

    /**
     * Records the end of the relationship on a concluded contract.
     *
     * @throws FieldException when the contract was never concluded, is already
     *                        terminated, or carries dates in the wrong order
     */
    public function terminate(ContractInterface $contract, ContractTerminationInputInterface $input): void;

    /** How long a sealed contract must be kept, in years, floor included. */
    public function retentionYears(): int;

    /**
     * Seals the document: reference, snapshot, rendered HTML and hash, in one
     * call, before the link goes out.
     *
     * @throws FrozenContractIsImmutableException when already frozen
     * @throws FieldException                     when the wording cannot produce a document
     */
    /**
     * The contract as it will read, before anything is sealed.
     *
     * @return array{html: string, unknownTokens: list<string>}
     */
    public function preview(ContractInterface $contract): array;

    public function freeze(ContractInterface $contract): void;
}
