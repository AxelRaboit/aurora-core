<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;

// Paths are resolved against the project root (getcwd()) so the config can be
// loaded from vendor/axelraboit/aurora/tools/rector/rector.php in client
// projects without scanning vendor's own source tree.
$projectDir = getcwd() ?: __DIR__.'/../..';

return RectorConfig::configure()
    // fixtures/ n'existe que dans aurora-core ; ce fichier sert aussi de
    // fallback aux clients, qui n'en ont pas, et Rector echoue sur un chemin
    // absent.
    ->withPaths(array_values(array_filter(
        [$projectDir.'/src', $projectDir.'/fixtures'],
        is_dir(...),
    )))
    ->withSkip([$projectDir.'/config'])
    ->withImportNames(removeUnusedImports: true)
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        codeQuality: true,
        codingStyle: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true
    )
    ->withTypeCoverageLevel(36)
    ->withDeadCodeLevel(40)
    ->withComposerBased(
        doctrine: true,
        symfony: true
    )
    ->withPHPStanConfigs([__DIR__.'/../phpstan/phpstan.neon'])
    ->withCache($projectDir.'/var/cache/rector', FileCacheStorage::class);
