<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function mb_str_split;
use function preg_match;

final class SirenValidator extends ConstraintValidator
{
    /**
     * La Poste's SIREN, the same documented exception as for the SIRET: its
     * numbers follow a rule of their own. A nine-digit SIREN still passes Luhn
     * like the others - the exception only covers an establishment's fourteen
     * digits - so nothing special here. The constant does not exist:
     * mentioning an exception that does not apply would suggest it applies.
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Siren) {
            throw new UnexpectedTypeException($constraint, Siren::class);
        }

        // A missing SIREN is not a wrong SIREN: the field is optional, and
        // `NotBlank` is the constraint that would say otherwise.
        if (null === $value || '' === $value) {
            return;
        }

        $siren = (string) $value;

        if (1 !== preg_match('/^\d{9}$/', $siren) || !$this->passesLuhn($siren)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }

    /** Luhn over the nine digits, right to left, every other one doubled. */
    private function passesLuhn(string $siren): bool
    {
        $total = 0;

        foreach (mb_str_split($siren) as $index => $digit) {
            $digit = (int) $digit;

            // Positions are counted from the left and the length is already
            // guaranteed odd: so the odd indexes are the ones Luhn doubles
            // when counting from the right.
            if (1 === $index % 2) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $total += $digit;
        }

        return 0 === $total % 10;
    }
}
