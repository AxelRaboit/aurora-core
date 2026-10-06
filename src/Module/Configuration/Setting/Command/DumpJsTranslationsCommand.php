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
 * - Writes {auroraDirectory}/src/Core/assets/locales/generated/{locale}.json (gitignored).
 *
 * `src/Core/assets/i18n.js` deep-merges these generated catalogues with manual JS source files
 * (src/Core/assets/locales/source/{locale}.js), with YAML winning on conflicts.
 *
 * Standalone aurora-core: $auroraDirectory = $projectDirectory, $extraSourceDirectories = [].
 * Aurora-client project:  $auroraDirectory = vendor/axelraboit/aurora, $extraSourceDirectories = client module translations.
 */
#[AsCommand(name: 'app:translations:dump-js', description: 'Dump Symfony YAML translations as JSON for vue-i18n')]
final class DumpJsTranslationsCommand extends Command
{
    private const string OUTPUT_DIR = 'src/Core/assets/locales/generated';

    /**
     * @param list<string> $extraSourceDirectories absolute paths to additional translation dirs
     */
    public function __construct(
        private readonly string $auroraDirectory,
        private readonly array $extraSourceDirectories = [],
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDirectory = Path::join($this->auroraDirectory, self::OUTPUT_DIR);

        try {
            $this->filesystem->mkdir($outputDirectory);
        } catch (IOException) {
            $io->error('Cannot create output directory: '.$outputDirectory);

            return Command::FAILURE;
        }

        foreach (LocaleEnum::values() as $locale) {
            $merged = [];
            $sourcesFound = 0;

            foreach ($this->discoverAuroraSourceDirs() as $relativeDirectory) {
                $sourcePath = Path::join($this->auroraDirectory, $relativeDirectory, sprintf('messages.%s.yaml', $locale));
                if ($this->mergeIfExists($sourcePath, $merged)) {
                    ++$sourcesFound;
                }
            }

            foreach ($this->extraSourceDirectories as $absoluteDirectory) {
                $sourcePath = Path::join($absoluteDirectory, sprintf('messages.%s.yaml', $locale));
                if ($this->mergeIfExists($sourcePath, $merged)) {
                    ++$sourcesFound;
                }
            }

            if (0 === $sourcesFound) {
                $io->warning(sprintf("No translation file found for locale '%s'", $locale));
                continue;
            }

            $converted = $this->convertPlaceholders($merged);

            $outPath = Path::join($outputDirectory, sprintf('%s.json', $locale));
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
     * @return list<string> relative paths from $auroraDirectory
     */
    private function discoverAuroraSourceDirs(): array
    {
        $directories = [];

        if (is_dir(Path::join($this->auroraDirectory, 'src/Core/translations'))) {
            $directories[] = 'src/Core/translations';
        }

        $found = array_merge(
            glob(Path::join($this->auroraDirectory, 'src/Core/*/translations'), GLOB_ONLYDIR) ?: [],
            glob(Path::join($this->auroraDirectory, 'src/Core/*/*/translations'), GLOB_ONLYDIR) ?: [],
        );
        foreach ($found as $absolutePath) {
            $directories[] = Path::makeRelative($absolutePath, $this->auroraDirectory);
        }

        $found = array_merge(
            glob(Path::join($this->auroraDirectory, 'src/Module/*/translations'), GLOB_ONLYDIR) ?: [],
            glob(Path::join($this->auroraDirectory, 'src/Module/*/*/translations'), GLOB_ONLYDIR) ?: [],
        );
        foreach ($found as $absolutePath) {
            $directories[] = Path::makeRelative($absolutePath, $this->auroraDirectory);
        }

        // À-la-carte install: extracted modules ship as sibling Composer
        // packages (vendor/axelraboit/aurora-<module>), so their translations
        // live OUTSIDE $auroraDirectory. Discover them when $auroraDirectory is itself a
        // vendored package (its parent is the `axelraboit` vendor dir) - the
        // gate keeps standalone aurora-core (modules under src/Module) from
        // globbing unrelated sibling projects.
        if ('axelraboit' === basename(dirname($this->auroraDirectory))) {
            $vendorNamespaceDirectory = dirname($this->auroraDirectory);
            $siblings = array_merge(
                glob(Path::join($vendorNamespaceDirectory, 'aurora-*/translations'), GLOB_ONLYDIR) ?: [],
                glob(Path::join($vendorNamespaceDirectory, 'aurora-*/*/translations'), GLOB_ONLYDIR) ?: [],
            );
            foreach ($siblings as $absolutePath) {
                $directories[] = Path::makeRelative($absolutePath, $this->auroraDirectory);
            }
        }

        return $directories;
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
     *  - Braces that name nothing (`function bonjour() { … }`) escaped as `{'{'}`, so
     *    vue-i18n's message compiler reads them as text rather than as a placeholder.
     *  - Bare `@` characters (e.g. in `you@example.com` placeholders) escaped as `{'@'}` so
     *    vue-i18n's linked-message parser doesn't treat them as `@:other.key` syntax.
     */
    private function convertPlaceholders(mixed $value): mixed
    {
        if (is_string($value)) {
            $converted = preg_replace('/%([A-Za-z_]\w*)%/', '{$1}', $value) ?? $value;

            return str_replace('@', "{'@'}", $this->escapeStrayBraces($converted));
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->convertPlaceholders($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Leaves `{name}` and `{'literal'}` alone and quotes every other brace.
     *
     * A literal is vue-i18n's own quoting, and the one way to show a `|`,
     * which would otherwise split a message into plural forms - something
     * this command cannot decide for the author, since the project's plural
     * messages are written with the very same bar. Quoting it again turned
     * `{'|'}` into text with stray quotes.
     *
     * vue-i18n compiles each message the first time it is rendered, and a
     * brace opens a placeholder: `function bonjour() { … }` reaches its parser
     * as a placeholder named `…`, which is not a name, so the compiler throws
     * and the component rendering that message renders nothing at all. That is
     * how the grid's code zone lost its two fields - the screen showed a zone
     * with no snippet field and no error, and only the console said why.
     *
     * A YAML author should not have to know any of this. They write the
     * sentence, or the snippet, that belongs on the screen; the escaping is
     * this command's business, exactly as it already is for `@`.
     */
    private function escapeStrayBraces(string $value): string
    {
        return preg_replace_callback(
            "/\\{'[^']*'\\}|\\{[A-Za-z0-9_]+\\}|[{}]/",
            static fn (array $match): string => 1 === mb_strlen($match[0])
                ? sprintf("{'%s'}", $match[0])
                : $match[0],
            $value,
        ) ?? $value;
    }

    private function countLeaves(array $tree): int
    {
        $count = 0;
        foreach ($tree as $node) {
            $count += is_array($node) ? $this->countLeaves($node) : 1;
        }

        return $count;
    }
}
