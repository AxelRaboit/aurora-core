<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * Les lignes que l'onglet GitHub possède, pour que la synchronisation de
 * déploiement ne les efface pas.
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
