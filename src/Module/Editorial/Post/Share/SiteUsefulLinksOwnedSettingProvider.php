<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Share;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * The row the useful links tab writes, so the deploy-time sync leaves it be:
 * without a claim, `aurora:application-parameter` deletes it at the next
 * release and every page loses its links at once.
 */
final readonly class SiteUsefulLinksOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        yield SiteUsefulLinks::KEY;
    }
}
