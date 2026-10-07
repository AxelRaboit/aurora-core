<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * A nine-digit SIREN that passes its own check digit.
 *
 * The same reason as {@see Siret}: what really happens is a transposed
 * digit, which keeps the length and changes the company. The check digit
 * catches exactly that, at input time and not once the number has been
 * copied into a document.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Siren extends Constraint
{
    public function __construct(
        public string $message = 'suite.studio.customers.errors.siren_invalid',
        mixed $options = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct($options, $groups, $payload);
    }
}
