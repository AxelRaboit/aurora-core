<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * The three rows the Craft tab owns, so that the deployment sync leaves them
 * alone.
 *
 * Not an `ApplicationParameterProviderInterface`, for the reason given by
 * {@see CraftSettingEnum}: the generic screen would draw the token in a
 * page. This one says the narrower thing - these rows exist and belong to
 * someone - which is all that is needed to stop erasing them.
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
