<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Un SIREN de neuf chiffres, qui passe sa propre clé.
 *
 * La même raison que {@see Siret} : ce qui arrive vraiment est un chiffre
 * transposé, qui garde la longueur et change l'entreprise. La clé attrape
 * exactement cela, au moment de la saisie et non une fois le numéro recopié
 * dans un document.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Siren extends Constraint
{
    public function __construct(
        public string $message = 'backend.studio.customers.errors.siren_invalid',
        mixed $options = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct($options, $groups, $payload);
    }
}
