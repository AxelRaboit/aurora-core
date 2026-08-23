<?php

declare(strict_types=1);

namespace Aurora\Core\Sequence;

/**
 * Declares a set of sequence prefixes for a given module or client app.
 *
 * Implement this interface and tag your class with `aurora.sequence_prefix`
 * (done automatically via the `_instanceof` rule in services.yaml).
 * Aurora will validate at boot that no two providers share the same prefix value.
 *
 * Example (client app):
 *
 *   final class ClientSequencePrefixProvider implements SequencePrefixProviderInterface
 *   {
 *       public function values(): array
 *       {
 *           return array_column(ClientPrefixEnum::cases(), 'value');
 *       }
 *
 *       public function name(): string { return 'My Client App'; }
 *   }
 */
interface SequencePrefixProviderInterface
{
    /** @return list<string> All prefix string values declared by this provider. */
    public function values(): array;

    /** Human-readable source name - used in conflict error messages. */
    public function name(): string;
}
