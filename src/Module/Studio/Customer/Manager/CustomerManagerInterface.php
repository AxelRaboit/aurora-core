<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Manager;

use Aurora\Module\Studio\Customer\Dto\CustomerInputInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;

interface CustomerManagerInterface
{
    public function create(CustomerInputInterface $input): CustomerInterface;

    /**
     * The whole sheet, from the customer's page: the only write path.
     *
     * A space's Informations tab no longer writes it, it shows it and leads
     * here. A whole input applies whole: a missing field is empty.
     */
    public function update(CustomerInterface $customer, CustomerInputInterface $input): void;

    /**
     * A prospect becomes a customer.
     *
     * The address is the only field asked for, because it is the only one the
     * status requires: it is where their contract goes. The rest of the legal
     * identity is filled in on their sheet, when it is known.
     */
    public function convertToClient(CustomerInterface $customer, ?string $contractualEmail): void;

    public function delete(CustomerInterface $customer): void;
}
