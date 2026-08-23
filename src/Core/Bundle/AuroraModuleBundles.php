<?php

declare(strict_types=1);

namespace Aurora\Core\Bundle;

use function dirname;
use function is_array;
use function is_string;

/**
 * Auto-discovers the Symfony bundle classes of every installed Aurora module
 * package (vendor/axelraboit/aurora-*) so a client never edits config/bundles.php
 * when it (un)installs a module - `composer require` is enough.
 *
 * Bundles are registered before the container exists, so this cannot be a
 * service; the client spreads it into its bundles.php array:
 *
 *     // config/bundles.php
 *     return [
 *         Aurora\AuroraBundle::class => ['all' => true],
 *         ...Aurora\Core\Bundle\AuroraModuleBundles::all(\dirname(__DIR__)),
 *         // ... framework bundles ...
 *     ];
 *
 * Each module package declares its bundle(s) in composer.json via
 * `extra.aurora.bundles` (array, e.g. aurora-commerce ships two) or, falling
 * back, the standard `extra.symfony.bundle` (single). In the monorepo the glob
 * matches nothing (modules live in src/), so this returns an empty array there.
 */
final class AuroraModuleBundles
{
    /**
     * @param array<string, bool> $envs environments map applied to each bundle
     *
     * @return array<class-string, array<string, bool>>
     */
    public static function all(string $projectDir, array $envs = ['all' => true]): array
    {
        $bundles = [];
        foreach (self::discover($projectDir) as $class) {
            $bundles[$class] = $envs;
        }

        return $bundles;
    }

    /**
     * @return list<class-string>
     */
    public static function discover(string $projectDir): array
    {
        $pattern = $projectDir.'/vendor/axelraboit/aurora-*/composer.json';
        $classes = [];

        foreach (glob($pattern) ?: [] as $composerFile) {
            // Skip aurora-core itself (its bundle is registered explicitly).
            if ('aurora-core' === basename(dirname($composerFile))) {
                continue;
            }

            $contents = file_get_contents($composerFile);
            if (false === $contents) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true) ?: [];
            $extra = $data['extra'] ?? [];

            $declared = $extra['aurora']['bundles'] ?? $extra['symfony']['bundle'] ?? null;
            foreach (is_array($declared) ? $declared : [$declared] as $class) {
                if (is_string($class) && class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }
}
