<?php

declare(strict_types=1);

namespace Aurora\Core\Bootstrap;

/**
 * One line of what a bootstrap run did: a row seeded, or a provider that
 * could not run.
 *
 * A value object rather than two callbacks so the caller decides how to
 * report — the command prints, the test suite ignores the labels and only
 * cares that nothing failed.
 */
final readonly class BootstrapResult
{
    private function __construct(
        public bool $success,
        public string $label,
        public ?string $error = null,
    ) {}

    public static function created(string $label): self
    {
        return new self(true, $label);
    }

    public static function failed(string $provider, string $error): self
    {
        return new self(false, $provider, $error);
    }
}
