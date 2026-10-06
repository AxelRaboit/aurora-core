<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Serializer;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;

interface CustomerInformationSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(CustomerInterface $customer): array;

    /**
     * Whether the sheet says anything more than the name.
     *
     * What the customer's tab checks to know whether it has a reason to exist:
     * a name, the customer already knows, it is at the top of the page.
     */
    public function hasContent(CustomerInterface $customer): bool;
}
