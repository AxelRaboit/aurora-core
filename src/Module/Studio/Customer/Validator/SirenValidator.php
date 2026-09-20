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
     * Le SIREN de La Poste, la même exception documentée que pour le SIRET :
     * ses numéros suivent une règle à part. Un SIREN de neuf chiffres passe
     * pourtant Luhn comme les autres - l'exception ne porte que sur les
     * quatorze chiffres d'un établissement - donc rien de particulier ici.
     * La constante n'existe pas : mentionner une exception qui ne s'applique
     * pas ferait croire qu'elle s'applique.
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Siren) {
            throw new UnexpectedTypeException($constraint, Siren::class);
        }

        // Un SIREN absent n'est pas un SIREN faux : le champ est facultatif,
        // et `NotBlank` est la contrainte qui dirait le contraire.
        if (null === $value || '' === $value) {
            return;
        }

        $siren = (string) $value;

        if (1 !== preg_match('/^\d{9}$/', $siren) || !$this->passesLuhn($siren)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }

    /** Luhn sur les neuf chiffres, de droite à gauche, un sur deux doublé. */
    private function passesLuhn(string $siren): bool
    {
        $total = 0;

        foreach (mb_str_split($siren) as $index => $digit) {
            $digit = (int) $digit;

            // Les positions se comptent depuis la gauche et la longueur est
            // déjà garantie impaire : ce sont donc les indices impairs que
            // Luhn double quand on compte depuis la droite.
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
