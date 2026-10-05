<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * A SIRET that is fourteen digits AND passes its own checksum.
 *
 * Length alone catches nothing useful: the mistakes that reach a contract are
 * transposed and mistyped digits, which keep the length and change the number.
 * The checksum catches exactly those, at the moment the customer is recorded
 * rather than after the number has been printed into something signed.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Siret extends Constraint
{
    public function __construct(
        public string $message = 'suite.studio.customers.errors.siret_invalid',
        mixed $options = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct($options, $groups, $payload);
    }
}
