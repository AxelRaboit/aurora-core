<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;
use Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface;
use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;
use BackedEnum;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function dirname;

/**
 * Every settings key a tab writes must be claimed by somebody.
 *
 * `aurora:application-parameter` runs on every deploy and deletes any row no
 * provider vouches for. A tab that writes its own rows and declares none loses
 * them at the next release, silently, on a production that worked an hour
 * earlier.
 *
 * That has now happened twice: to the Pexels API key, and on 12/09/2026 to the
 * eight storage rows, an hour after the bucket was configured and verified.
 * Both were found by a deployment rather than by a test. The third tab to
 * forget should fail here instead.
 *
 * The rule, read off the four tabs that exist: an enum drawn by the generic
 * settings screen implements {@see ApplicationParameterEnumInterface} and is
 * declared by a parameter provider. An enum that is not - because it holds a
 * secret no generic screen should render - has to be claimed by an
 * {@see OwnedSettingProviderInterface} instead.
 */
final class OwnedSettingCoverageTest extends TestCase
{
    public function testEveryPrivateSettingEnumIsClaimedByAnOwner(): void
    {
        $claimed = [];

        foreach ($this->classesImplementing(OwnedSettingProviderInterface::class) as $class) {
            /** @var OwnedSettingProviderInterface $provider */
            $provider = new $class();

            foreach ($provider->getOwnedSettingKeys() as $key) {
                $claimed[$key] = $class;
            }
        }

        $orphans = [];

        foreach ($this->settingEnums() as $enum) {
            // Drawn by the generic screen, therefore already declared as an
            // application parameter. Not this test's business.
            if (is_a($enum, ApplicationParameterEnumInterface::class, true)) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                if (!isset($claimed[$case->value])) {
                    $orphans[] = sprintf('%s::%s → "%s"', $enum, $case->name, $case->value);
                }
            }
        }

        self::assertSame(
            [],
            $orphans,
            "These settings rows are written by a tab and claimed by no provider, so the\n"
            ."deploy-time sync deletes them at the next release. Add an\n"
            ."OwnedSettingProviderInterface next to the enum:\n  ".implode("\n  ", $orphans),
        );
    }

    /**
     * The other half of the rule, which the test above took on trust.
     *
     * An enum drawn by the settings screen is only safe if a parameter
     * provider yields it. Three did not - the editorial, document library and
     * markdown note ones - so every value saved for them was deleted by the
     * next deploy, and nothing here noticed because the first test skips them
     * on the assumption that somebody declared them. Found on 27/09/2026.
     */
    public function testEverySettingsScreenEnumIsDeclaredByAParameterProvider(): void
    {
        $declared = [];

        foreach ($this->classesImplementing(ApplicationParameterProviderInterface::class) as $class) {
            /** @var ApplicationParameterProviderInterface $provider */
            $provider = new $class();

            foreach ($provider->getParameters() as $parameter) {
                $declared[$parameter->getKey()] = $class;
            }
        }

        $undeclared = [];

        foreach ($this->settingEnums() as $enum) {
            if (!is_a($enum, ApplicationParameterEnumInterface::class, true)) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                if (!isset($declared[$case->getKey()])) {
                    $undeclared[] = sprintf('%s::%s → "%s"', $enum, $case->name, $case->getKey());
                }
            }
        }

        self::assertSame(
            [],
            $undeclared,
            "These settings are drawn by the settings screen and declared by no parameter\n"
            ."provider, so the deploy-time sync deletes whatever was saved for them. Add an\n"
            ."ApplicationParameterProviderInterface next to the enum:\n  ".implode("\n  ", $undeclared),
        );
    }

    /**
     * The scan has to find something, or a green result would mean nothing.
     */
    public function testTheScanSeesBothSidesOfTheRule(): void
    {
        self::assertNotEmpty($this->settingEnums());
        self::assertNotEmpty($this->classesImplementing(OwnedSettingProviderInterface::class));
    }

    /**
     * Every backed enum named `*SettingEnum` under `src/`.
     *
     * Found by filename, because the omission being guarded against is exactly
     * that nothing was implemented: an interface-based scan would miss the
     * enums that forgot one.
     *
     * @return list<class-string<BackedEnum>>
     */
    private function settingEnums(): array
    {
        return array_values(array_filter(
            $this->declaredClasses('SettingEnum.php'),
            static fn (string $class): bool => enum_exists($class) && is_a($class, BackedEnum::class, true),
        ));
    }

    /**
     * @param class-string $interface
     *
     * @return list<class-string>
     */
    private function classesImplementing(string $interface): array
    {
        return array_values(array_filter(
            $this->declaredClasses('.php'),
            static function (string $class) use ($interface): bool {
                if (!class_exists($class) || !is_a($class, $interface, true)) {
                    return false;
                }

                $reflection = new ReflectionClass($class);

                return $reflection->isInstantiable()
                    && 0 === ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0);
            },
        ));
    }

    /**
     * Class names resolved from the files under `src/` whose name ends in
     * `$suffix`, read from the source rather than from the autoloader: the
     * point is to see what exists, not what something already asked for.
     *
     * @return list<string>
     */
    private function declaredClasses(string $suffix): array
    {
        $root = dirname(__DIR__, 5).'/src';
        $classes = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), $suffix)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (1 !== preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
                continue;
            }

            $classes[] = mb_trim($namespace[1]).'\\'.$file->getBasename('.php');
        }

        return $classes;
    }
}
