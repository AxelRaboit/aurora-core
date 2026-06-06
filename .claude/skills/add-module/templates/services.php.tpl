<?php

declare(strict_types=1);

/**
 * Services config shipped INSIDE the aurora-{{MODULE_KEBAB}} package. When the
 * module is a standalone Composer package, aurora-core's central `Aurora\:
 * resource` glob does not cover {{MODULE}} (it is excluded in
 * config/services.yaml), so the module registers its own services + the tags
 * for the core interfaces it implements. Loaded by Aurora{{MODULE}}Bundle's
 * loadExtension (via AbstractAuroraModuleBundle) — the `instanceof()` calls are
 * file-scoped, so they never collide with the central `_instanceof`.
 */

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface;
{{SERVICES_EXTRA_USE}}use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $moduleDir = \dirname(__DIR__);

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    // Tag the core interfaces this module's services implement (covered by
    // aurora-core's central _instanceof in the monorepo, but a standalone
    // package must declare them for its own services).
    $services->instanceof(ModuleInterface::class)->tag('aurora.module');
    $services->instanceof(ApplicationParameterProviderInterface::class)
        ->tag('aurora.application_parameter_provider');
{{SERVICES_EXTRA_INSTANCEOF}}
    $services->load('Aurora\\Module\\{{MODULE}}\\', $moduleDir.'/')
        ->exclude([
            $moduleDir.'/Aurora{{MODULE}}Bundle.php',
            $moduleDir.'/{config,templates,translations,assets}',
            $moduleDir.'/**/Entity',
            $moduleDir.'/Setting/{{MODULE}}ModuleParameterEnum.php',
        ]);
};
