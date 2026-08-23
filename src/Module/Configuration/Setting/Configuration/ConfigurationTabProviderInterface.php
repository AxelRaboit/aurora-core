<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Configuration;

/**
 * Implemented by services that contribute tabs to the admin Settings page.
 * Tagged `aurora.configuration_tab_provider` (see config/services.yaml) so
 * the {@see SettingDefinitionRegistry} can discover every provider - both
 * Aurora's built-in {@see CoreConfigurationTabProvider} and any aurora-client
 * provider injected by a downstream app.
 *
 * Implementations are called at request time (not compile time), so provider
 * code can resolve dynamic option lists, read other settings, query
 * repositories, etc. Keep it cheap - `getTabs()` is invoked once per
 * Settings page render.
 */
interface ConfigurationTabProviderInterface
{
    /**
     * @return list<ConfigurationTab>
     */
    public function getTabs(): array;
}
