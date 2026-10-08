<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function mb_strtolower;
use function preg_match_all;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function token_get_all;

use const JSON_THROW_ON_ERROR;
use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * A declared dependency is named after **what it is**, not after what it
 * returns.
 *
 * `private MarkdownNoteRepository $notes` names the collection the repository
 * loads, so the name reads as data when it is a collaborator. Seven files had
 * reached the point where the same word meant both at once - the repository as
 * `$this->spaces`, the list it returned as `$spaces`, one line apart - and a
 * reader had to hold two senses in their head to follow the method.
 *
 * It was never a repository problem. A generator called `$pathTemplates` and a
 * formatter called `$dates` do exactly the same thing, which is why the rule
 * is written against a list of **role suffixes** rather than against one
 * class of service. 376 names across 220 files were renamed at once in October
 * 2026; this test keeps them from coming back.
 *
 * **The rule does not say the name must repeat the type.** It says the name
 * must carry the role: `$settingRepository` and `$repository` both pass, and
 * `$notes` does not. Which name to choose is a judgement; whether the role is
 * in it is not.
 *
 * The suffixes and the few exceptions live in
 * `tools/naming/full-word-names.json`, beside the abbreviation list that
 * {@see NamesAreFullWordsTest} reads - one file for everything the project
 * says about names.
 */
final class DependenciesAreNamedAfterTheirRoleTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const array DIRECTORIES = ['src', 'tests', 'fixtures', 'config'];

    /**
     * A declaration carrying a visibility keyword: a property, or a
     * constructor-promoted parameter. A plain parameter is left out on
     * purpose - its name is sometimes a wiring key (Symfony resolves
     * `$contractSignatureLimiter` to the `contract_signature` limiter, and
     * `config/services.yaml` passes a dozen arguments by name), and a test
     * that demanded a rename there would break the container rather than
     * improve a name.
     */
    private const string DECLARATION = '/\b(?:private|protected|public)\s+(?:readonly\s+)?\??([A-Za-z_]\w*)\s+\$(\w+)/';

    public function testEveryDeclaredDependencyCarriesItsRole(): void
    {
        $naming = json_decode((string) file_get_contents(self::ROOT.'/tools/naming/full-word-names.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($naming);

        $suffixes = $naming['roleSuffixes'];
        $exceptions = $naming['roleNameExceptions'];
        self::assertTrue(is_array($suffixes) && is_array($exceptions));

        $offenders = [];

        foreach ($this->files() as $file) {
            $relative = $this->relative($file);
            $source = $this->withoutComments((string) file_get_contents($file->getPathname()));

            preg_match_all(self::DECLARATION, $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $type, $variable]) {
                $suffix = $this->roleOf($type, $suffixes);

                if (null === $suffix || str_contains(mb_strtolower($variable), mb_strtolower($suffix))) {
                    continue;
                }

                if (isset($exceptions[sprintf('%s::$%s', $relative, $variable)])) {
                    continue;
                }

                $offenders[sprintf('%s: %s $%s - say which %s it is', $relative, $type, $variable, $suffix)] = true;
            }
        }

        $offenders = array_keys($offenders);
        sort($offenders);

        self::assertSame([], $offenders, sprintf(
            "These names announce the data instead of the dependency. A declared\n"
            ."dependency is named after what it is, not after what it returns:\n%s\n\n"
            ."The suffix list and the exceptions are in tools/naming/full-word-names.json.\n",
            implode("\n", $offenders),
        ));
    }

    /**
     * The file without its comments.
     *
     * A docblock has to be free to show the name it forbids - this very class
     * does, and flagged itself on the first run. {@see NamesAreFullWordsTest}
     * tokenises for the same reason.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** The role a type carries in its own name, or null. */
    private function roleOf(string $type, array $suffixes): ?string
    {
        foreach ($suffixes as $suffix) {
            // A class *called* `Repository` carries no subject to name it
            // after, so it is left out rather than forced to repeat itself.
            if (!is_string($suffix) || $type === $suffix || !str_ends_with($type, $suffix)) {
                continue;
            }

            return $suffix;
        }

        return null;
    }

    /** @return iterable<SplFileInfo> */
    private function files(): iterable
    {
        foreach (self::DIRECTORIES as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::ROOT.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                self::assertInstanceOf(SplFileInfo::class, $file);

                // `assets/` holds the Vue side, which the ESLint rule covers.
                if ($file->isFile() && 'php' === $file->getExtension() && !str_contains($file->getPathname(), '/assets/')) {
                    yield $file;
                }
            }
        }
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(realpath(self::ROOT).'/', '', (string) realpath($file->getPathname()));
    }
}
