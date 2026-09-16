<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Concern;

use function preg_replace;

/**
 * Compares two refusal pages without the parts that differ on every request.
 *
 * Several surfaces guarded by a secret address - a shared note, a contract, a
 * client space - answer a wrong secret, a revoked link and an expired one with
 * the *same* page. Telling them apart would tell a stranger which of their
 * guesses landed, and confirm that an address was once real. The tests that
 * protect that property compare the two bodies byte for byte, which is the
 * right way to state it.
 *
 * The `Content-Security-Policy` nonce broke those comparisons in 0.9.189: it
 * is sixteen random bytes regenerated per request, so it appears in every
 * rendered page and is never twice the same.
 *
 * **Stripping it does not weaken the assertion, and the reason matters.** A
 * value that differs between any two responses - including two identical
 * requests - carries no information about which refusal happened. What the
 * test is really asking is whether anything *derived from the outcome* differs,
 * and that is exactly what survives this.
 */
trait ComparesRefusalPages
{
    protected function withoutPerRequestNoise(string $html): string
    {
        return (string) preg_replace('/nonce="[a-f0-9]+"/', 'nonce="…"', $html);
    }
}
