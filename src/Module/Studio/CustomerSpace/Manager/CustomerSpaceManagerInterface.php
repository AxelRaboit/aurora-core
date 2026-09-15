<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Manager;

use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;

interface CustomerSpaceManagerInterface
{
    public function create(CustomerSpaceInputInterface $input): CustomerSpaceInterface;

    public function update(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void;

    public function delete(CustomerSpaceInterface $space): void;
}
