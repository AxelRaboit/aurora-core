<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Share;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

/**
 * The Configuration tab that holds the site's useful links. Drawn whole by
 * its own component, which reads and writes through its own route: a list of
 * links is not a field the generic screen knows how to edit.
 */
final readonly class UsefulLinksConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        return [
            new ConfigurationTab(id: 'useful_links', priority: 83, fields: [], alwaysVisible: true, componentName: 'useful_links'),
        ];
    }
}
