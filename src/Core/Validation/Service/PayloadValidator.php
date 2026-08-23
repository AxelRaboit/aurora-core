<?php

declare(strict_types=1);

namespace Aurora\Core\Validation\Service;

use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Thin wrapper around the Symfony validator dedicated to validating
 * Request DTOs. Returns either a flat field→message map or just the
 * first violation message - whichever fits the controller's response shape.
 *
 * Messages are returned as-is (typically translation keys defined in the
 * DTO's Assert constraints); translation happens at the rendering layer.
 */
final readonly class PayloadValidator
{
    public function __construct(private ValidatorInterface $validator) {}

    /**
     * @return array<string, string> Field path → first violation message
     */
    public function errors(object $dto): array
    {
        $errors = [];
        foreach ($this->validator->validate($dto) as $violation) {
            $field = $violation->getPropertyPath();
            $errors[$field] ??= (string) $violation->getMessage();
        }

        return $errors;
    }

    /**
     * Returns the first violation message, or null when the DTO is valid.
     * Useful for endpoints that surface a single top-level error.
     */
    public function firstError(object $dto): ?string
    {
        $violations = $this->validator->validate($dto);
        if (0 === count($violations)) {
            return null;
        }

        return (string) $violations[0]->getMessage();
    }
}
