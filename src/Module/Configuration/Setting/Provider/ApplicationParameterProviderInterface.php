<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Provider;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * Contributes a set of `ApplicationParameterEnumInterface` cases to the
 * `aurora:application-parameter` sync command. Implementations are
 * auto-tagged via `_instanceof` in services.yaml - any service
 * implementing this interface gets tagged `aurora.application_parameter_provider`
 * and is picked up by the command's tagged iterator.
 *
 * Aurora-core ships two default providers (one per built-in enum:
 * `ApplicationParameterEnum`, `ModuleParameterEnum`). Client projects
 * register their own settings by implementing this interface and
 * yielding their custom enum's cases.
 *
 *
 * Example (client side, after declaring a custom `<Module>SettingEnum`):
 *
 *     final readonly class <Module>ApplicationParameterProvider
 *         implements ApplicationParameterProviderInterface
 *     {
 *         public function getParameters(): iterable
 *         {
 *             yield from <Module>SettingEnum::cases();
 *         }
 *     }
 *
 * The client's `config/services.yaml` must declare the matching
 * `_instanceof` block so client-side implementations get the tag too
 * (the aurora-core `_instanceof` only applies inside the bundle config).
 * The template `client_template/config/services.yaml` ships this block
 * by default - newer clients inherit it automatically.
 *
 * Critically: the `aurora:application-parameter` command **deletes**
 * any `core_settings` row whose key isn't returned by *any* provider
 * (flagged "obsolète"). So a client custom enum **must** be exposed via
 * a provider - otherwise saved values will be wiped on the next sync.
 */
interface ApplicationParameterProviderInterface
{
    /** @return iterable<ApplicationParameterEnumInterface> */
    public function getParameters(): iterable;
}
