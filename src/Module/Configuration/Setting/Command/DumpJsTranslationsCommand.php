<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Command;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

/**
 * Dumps Symfony YAML translations to JSON files consumed by vue-i18n at runtime.
 *
 * - Scans messages.{locale}.yaml in every aurora module translations directory and deep-merges them.
 * - Also scans any extra source dirs supplied by client projects (custom modules).
 * - Converts Symfony-style `%var%` placeholders to vue-i18n-style `{var}`.
 * - Writes {auroraDir}/src/Core/assets/locales/generated/{locale}.json (gitignored).
 *
 * `src/Core/assets/i18n.js` deep-merges these generated catalogues with manual JS source files
 * (src/Core/assets/locales/source/{locale}.js), with YAML winning on conflicts.
 *
 * Standalone aurora-core: $auroraDir = $projectDir, $extraSourceDirs = [].
 * Aurora-client project:  $auroraDir = vendor/axelraboit/aurora, $extraSourceDirs = client module translations.
 */
#[AsCommand(name: 'app:translations:dump-js', description: 'Dump Symfony YAML translations as JSON for vue-i18n')]
final class DumpJsTranslationsCommand extends Command
{
    private const string OUTPUT_DIR = 'src/Core/assets/locales/generated';

    /**
     * @param list<string> $extraSourceDirs absolute paths to additional translation dirs
     */
    public function __construct(
        private readonly string $auroraDir,
        private readonly array $extraSourceDirs = [],
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = Path::join($this->auroraDir, self::OUTPUT_DIR);

        try {
            $this->filesystem->mkdir($outputDir);
        } catch (IOException) {
            $io->error('Cannot create output directory: '.$outputDir);

            return Command::FAILURE;
        }

        foreach (LocaleEnum::values() as $locale) {
            $merged = [];
            $sourcesFound = 0;

            foreach ($this->discoverAuroraSourceDirs() as $relativeDir) {
                $sourcePath = Path::join($this->auroraDir, $relativeDir, sprintf('messages.%s.yaml', $locale));
                if ($this->mergeIfExists($sourcePath, $merged)) {
                    ++$sourcesFound;
                }
            }

            foreach ($this->extraSourceDirs as $absoluteDir) {
                $sourcePath = Path::join($absoluteDir, sprintf('messages.%s.yaml', $locale));
                if ($this->mergeIfExists($sourcePath, $merged)) {
                    ++$sourcesFound;
                }
            }

            if (0 === $sourcesFound) {
                $io->warning(sprintf("No translation file found for locale '%s'", $locale));
                continue;
            }

            $converted = $this->convertPlaceholders($merged);

            $outPath = Path::join($outputDir, sprintf('%s.json', $locale));
            $this->filesystem->dumpFile(
                $outPath,
                json_encode($converted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
            $io->writeln(sprintf('  <info>✓</info> %s (%d keys from %d source(s))', basename($outPath), $this->countLeaves($converted), $sourcesFound));
        }

        $io->success('Translations dumped. vue-i18n will auto-pick them on next dev/build.');

        return Command::SUCCESS;
    }

    /**
     * Discovers translation source dirs at runtime: Core + all module translation dirs.
     *
     * @return list<string> relative paths from $auroraDir
     */
    private function discoverAuroraSourceDirs(): array
    {
        $dirs = [];

        if (is_dir(Path::join($this->auroraDir, 'src/Core/translations'))) {
            $dirs[] = 'src/Core/translations';
        }

        $found = array_merge(
            glob(Path::join($this->auroraDir, 'src/Core/*/translations'), GLOB_ONLYDIR) ?: [],
            glob(Path::join($this->auroraDir, 'src/Core/*/*/translations'), GLOB_ONLYDIR) ?: [],
        );
        foreach ($found as $absolutePath) {
            $dirs[] = Path::makeRelative($absolutePath, $this->auroraDir);
        }

        $found = array_merge(
            glob(Path::join($this->auroraDir, 'src/Module/*/translations'), GLOB_ONLYDIR) ?: [],
            glob(Path::join($this->auroraDir, 'src/Module/*/*/translations'), GLOB_ONLYDIR) ?: [],
        );
        foreach ($found as $absolutePath) {
            $dirs[] = Path::makeRelative($absolutePath, $this->auroraDir);
        }

        // À-la-carte install: extracted modules ship as sibling Composer
        // packages (vendor/axelraboit/aurora-<module>), so their translations
        // live OUTSIDE $auroraDir. Discover them when $auroraDir is itself a
        // vendored package (its parent is the `axelraboit` vendor dir) — the
        // gate keeps standalone aurora-core (modules under src/Module) from
        // globbing unrelated sibling projects.
        if ('axelraboit' === basename(dirname($this->auroraDir))) {
            $vendorNamespaceDir = dirname($this->auroraDir);
            $siblings = array_merge(
                glob(Path::join($vendorNamespaceDir, 'aurora-*/translations'), GLOB_ONLYDIR) ?: [],
                glob(Path::join($vendorNamespaceDir, 'aurora-*/*/translations'), GLOB_ONLYDIR) ?: [],
            );
            foreach ($siblings as $absolutePath) {
                $dirs[] = Path::makeRelative($absolutePath, $this->auroraDir);
            }
        }

        return $dirs;
    }

    /**
     * Returns true if the file existed and was merged into $merged.
     */
    private function mergeIfExists(string $sourcePath, array &$merged): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }

        $tree = Yaml::parseFile($sourcePath) ?? [];
        $merged = $this->deepMerge($merged, is_array($tree) ? $tree : []);

        return true;
    }

    /** Recursively merges $source into $target. Source wins on scalar conflicts. */
    private function deepMerge(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (is_array($value) && isset($target[$key]) && is_array($target[$key])) {
                $target[$key] = $this->deepMerge($target[$key], $value);
            } else {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    /**
     * Recursively prepares messages for vue-i18n consumption:
     *  - Symfony `%var%` placeholders → `{var}`
     *  - Bare `@` characters (e.g. in `you@example.com` placeholders) escaped as `{'@'}` so
     *    vue-i18n's linked-message parser doesn't treat them as `@:other.key` syntax.
     */
    private function convertPlaceholders(mixed $value): mixed
    {
        if (is_string($value)) {
            $converted = preg_replace('/%([A-Za-z_]\w*)%/', '{$1}', $value) ?? $value;

            return str_replace('@', "{'@'}", $converted);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->convertPlaceholders($v);
            }

            return $out;
        }

        return $value;
    }

    private function countLeaves(array $tree): int
    {
        $count = 0;
        foreach ($tree as $v) {
            $count += is_array($v) ? $this->countLeaves($v) : 1;
        }

        return $count;
    }
}
