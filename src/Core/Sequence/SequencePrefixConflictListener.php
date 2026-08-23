<?php

declare(strict_types=1);

namespace Aurora\Core\Sequence;

use LogicException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Validates that no two registered sequence prefix providers declare the same
 * prefix value. Runs once on the first request so it catches conflicts in all
 * environments (dev, test, prod) without any build-time ceremony.
 *
 * A conflict means two modules/apps would share the same PostgreSQL sequence
 * and interleave their reference numbers - a silent data corruption bug.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final class SequencePrefixConflictListener
{
    private bool $validated = false;

    /** @param iterable<SequencePrefixProviderInterface> $providers */
    public function __construct(private readonly iterable $providers) {}

    public function __invoke(RequestEvent $event): void
    {
        if ($this->validated || !$event->isMainRequest()) {
            return;
        }

        $this->validated = true;

        $seen = []; // value => provider name
        foreach ($this->providers as $provider) {
            foreach ($provider->values() as $value) {
                if (isset($seen[$value])) {
                    throw new LogicException(sprintf('[Aurora] Sequence prefix conflict: "%s" is declared by both "%s" and "%s". Each prefix must be globally unique - rename one of them.', $value, $seen[$value], $provider->name()));
                }

                $seen[$value] = $provider->name();
            }
        }
    }
}
