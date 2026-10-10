<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Hosting;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function explode;
use function str_contains;

/**
 * The modules that host note spaces, by their key.
 *
 * Also reads the host parameter a request carries
 * ({@see NoteSpaceScope::HOST_PARAMETER}): `<key>:<reference>`, the host's key
 * and its own identifier for the space, which the host alone resolves.
 */
final readonly class NoteSpaceHosts
{
    /** @param iterable<NoteSpaceHostInterface> $hosts */
    public function __construct(
        #[AutowireIterator(NoteSpaceHostInterface::TAG)]
        private iterable $hosts,
    ) {}

    public function get(string $key): ?NoteSpaceHostInterface
    {
        foreach ($this->hosts as $host) {
            if ($host->getKey() === $key) {
                return $host;
            }
        }

        return null;
    }

    /** The host of a space, null for a space of the Notes module itself. */
    public function of(NoteSpaceInterface $space): ?NoteSpaceHostInterface
    {
        $key = $space->getManagedBy();

        return null === $key ? null : $this->get($key);
    }

    /** The value of the host parameter for one of a host's spaces. */
    public function parameterFor(NoteSpaceHostInterface $host, string $reference): string
    {
        return $host->getKey().':'.$reference;
    }

    /**
     * The space a host parameter opens to this person, with its host and
     * reference - or null when the parameter is malformed, names no host, or
     * the host does not let them in. The caller answers 404 to all three.
     */
    public function enter(string $parameter, CoreUserInterface $user): ?HostedNoteSpace
    {
        if (!str_contains($parameter, ':')) {
            return null;
        }

        [$key, $reference] = explode(':', $parameter, 2);
        $host = $this->get($key);

        if (!$host instanceof NoteSpaceHostInterface || '' === $reference) {
            return null;
        }

        $space = $host->enter($reference, $user);

        // The host answers for its own spaces only: a space it does not mark
        // as its own is not one it may open on the engine's behalf.
        if (!$space instanceof NoteSpaceInterface || $space->getManagedBy() !== $host->getKey()) {
            return null;
        }

        return new HostedNoteSpace($space, $host, $reference);
    }
}
