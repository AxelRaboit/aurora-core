<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Manager;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerInteraction\Dto\CustomerInteractionInputInterface;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteractionInterface;

interface CustomerInteractionManagerInterface
{
    public function create(CustomerInterface $customer, CustomerInteractionInputInterface $input, ?CoreUserInterface $author): CustomerInteractionInterface;

    public function update(CustomerInteractionInterface $interaction, CustomerInteractionInputInterface $input): void;

    public function delete(CustomerInteractionInterface $interaction): void;
}
