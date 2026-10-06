<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * The rows the GitHub tab owns, so that the deployment sync does not erase
 * them.
 */
final readonly class GitHubOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (GitHubSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
