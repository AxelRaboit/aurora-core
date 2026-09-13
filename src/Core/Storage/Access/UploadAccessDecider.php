<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Access;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function str_contains;
use function str_starts_with;

/**
 * The one place that answers "may this visitor read this key".
 *
 * Guards arrive through the `aurora.upload_access_guard` tag, so an area
 * gates itself by adding a class rather than by editing this one, and a
 * client's own area can do the same from its own bundle.
 *
 * **Unclaimed areas stay anonymous.** That is deliberate and it is the part
 * worth arguing about, because the safer-sounding default - deny what nobody
 * claims - would silently take down every client that stores something under
 * a prefix aurora-core has never heard of. The prefixes that hold private
 * files are known and claimed; a default that breaks strangers to protect
 * files that are already protected buys nothing.
 */
final readonly class UploadAccessDecider
{
    /** @param iterable<UploadAccessGuardInterface> $guards */
    public function __construct(
        #[AutowireIterator('aurora.upload_access_guard')]
        private iterable $guards,
    ) {}

    public function decide(string $key): UploadAccessEnum
    {
        // A key that is not a key. The controller's own traversal guard and
        // the adapters both refuse these, but the decision must not depend on
        // having run first: a guard matching on a prefix would read
        // `ged/../contracts/…` as belonging to the GED.
        if (str_contains($key, '..') || str_starts_with($key, '/')) {
            return UploadAccessEnum::Denied;
        }

        foreach ($this->guards as $guard) {
            if ($guard->supports($key)) {
                return $guard->decide($key);
            }
        }

        return UploadAccessEnum::Anonymous;
    }
}
