<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Craft\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * Les trois lignes que l'onglet Craft possède, pour que la synchronisation de
 * déploiement les laisse tranquilles.
 *
 * Pas un `ApplicationParameterProviderInterface`, pour la raison que donne
 * {@see CraftSettingEnum} : l'écran générique dessinerait le jeton dans une
 * page. Celui-ci dit la chose plus étroite - ces lignes existent et sont à
 * quelqu'un - qui est tout ce qu'il faut pour cesser de les effacer.
 */
final readonly class CraftOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (CraftSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
