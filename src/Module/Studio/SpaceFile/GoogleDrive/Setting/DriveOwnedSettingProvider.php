<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * Les deux lignes que l'onglet Drive possède, pour que la synchronisation de
 * déploiement les laisse tranquilles.
 *
 * Sans cela, la clé disparaîtrait à la release suivante et l'intégration
 * s'éteindrait sans que personne comprenne pourquoi.
 */
final readonly class DriveOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (DriveSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
