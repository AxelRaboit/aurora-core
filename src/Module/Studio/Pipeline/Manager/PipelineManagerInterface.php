<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Manager;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;

interface PipelineManagerInterface
{
    /**
     * Puts these customers in this stage, in this order.
     *
     * @param list<int> $customerIds the stage's whole new order
     * @param ?string   $lostReason  why the deal was lost, when the stage is the lost one
     */
    public function move(PipelineStageInterface $stage, array $customerIds, ?string $lostReason = null): void;

    /** Files a customer who just became a client under the won stage, if there is one. */
    public function fileAsWon(CustomerInterface $customer): void;
}
