<?php

declare(strict_types=1);

namespace Aurora\Core\Http\Csp;

use function bin2hex;
use function random_bytes;

/**
 * One nonce per request, shared by the header and every tag that needs it.
 *
 * A service rather than a value passed around, because the two ends are far
 * apart: a Twig template asks for it while the page is being built, and the
 * subscriber writes it into the header after. Generating it in either place
 * would leave the other with a different one, and a nonce that does not match
 * is a script that does not run.
 *
 * Generated on first use and never again within the request. Sixteen bytes
 * from the CSPRNG: a nonce anybody can predict is a nonce anybody can borrow.
 */
final class CspNonce
{
    private ?string $value = null;

    public function value(): string
    {
        return $this->value ??= bin2hex(random_bytes(16));
    }

    /**
     * Whether anything actually asked for it.
     *
     * The header is written on every HTML response, but a page with no inline
     * script never calls {@see value()}. Knowing that lets the subscriber leave
     * the nonce out of a policy that has no use for one, which keeps the header
     * honest about what the page contains.
     */
    public function wasUsed(): bool
    {
        return null !== $this->value;
    }

    /** Between requests in a worker runtime, where the service outlives one. */
    public function reset(): void
    {
        $this->value = null;
    }
}
