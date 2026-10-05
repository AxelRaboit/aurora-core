<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Translation;

use Aurora\Core\Locale\Enum\LocaleEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff_key;
use function array_intersect_key;
use function array_keys;
use function count;
use function implode;
use function is_array;
use function is_string;
use function preg_match_all;
use function sort;
use function sprintf;

/**
 * Everything a customer reads exists in every language the application offers.
 *
 * The rest of the back office falls back to French in Spanish, a limitation
 * carried since 0.9.69 and an acceptable one: whoever administers contracts
 * chose the language they work in. A signer did not. A contract is issued in a
 * locale, and that locale drives the public page, the signature form, the
 * emails and the PDF - so a missing Spanish key here does not degrade an
 * interface, it hands somebody a document to sign in a language they did not
 * ask for, half of it in French.
 *
 * Hence the boundary this test draws: the `studio` tree, which is what
 * leaves the building, has to be complete in fr, en and es. The `suite` tree
 * is not asserted, and that omission is the decision rather than an oversight.
 *
 * Placeholders are compared too. A translation that dropped `{date}` would
 * print a sentence with a hole in it inside a sealed document, and the seal
 * would then make it permanent.
 */
final class CustomerFacingLocaleTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../../../../src/Module/Studio/translations';

    /** The tree a customer reads. Anything under `suite` is out of scope. */
    private const string TREE = 'studio';

    public function testEveryLocaleCarriesTheWholeCustomerFacingTree(): void
    {
        $reference = $this->tree(LocaleEnum::French->value);

        self::assertNotSame([], $reference, 'the French customer-facing tree could not be read');

        foreach (LocaleEnum::values() as $locale) {
            if (LocaleEnum::French->value === $locale) {
                continue;
            }

            $translated = $this->tree($locale);

            $missing = array_diff_key($reference, $translated);

            self::assertSame(
                [],
                array_keys($missing),
                sprintf(
                    'a contract issued in "%s" would reach its signer partly in French: %s',
                    $locale,
                    implode(', ', array_keys($missing)),
                ),
            );

            $extra = array_diff_key($translated, $reference);

            self::assertSame(
                [],
                array_keys($extra),
                sprintf('"%s" carries keys French does not: %s', $locale, implode(', ', array_keys($extra))),
            );
        }
    }

    public function testEveryLocaleKeepsThePlaceholders(): void
    {
        $reference = $this->tree(LocaleEnum::French->value);

        foreach (LocaleEnum::values() as $locale) {
            if (LocaleEnum::French->value === $locale) {
                continue;
            }

            foreach (array_intersect_key($reference, $this->tree($locale)) as $key => $french) {
                $expected = $this->placeholders($french);
                $actual = $this->placeholders($this->tree($locale)[$key]);

                self::assertSame(
                    $expected,
                    $actual,
                    sprintf(
                        '[%s] "%s" would print a sentence with a hole in it, inside a sealed document: expected {%s}, found {%s}',
                        $locale,
                        $key,
                        implode('}, {', $expected),
                        implode('}, {', $actual),
                    ),
                );
            }
        }
    }

    /**
     * The clause the module writes into the document needs a name for every
     * language, in every language: a Spanish contract governed by the French
     * version says "la versión francesa", and the word has to exist.
     */
    public function testEveryLanguageHasANameInEveryLanguage(): void
    {
        foreach (LocaleEnum::values() as $locale) {
            $tree = $this->tree($locale);

            foreach (LocaleEnum::values() as $named) {
                self::assertArrayHasKey(
                    'contract.language.'.$named,
                    $tree,
                    sprintf('the governing-language clause of a "%s" contract cannot name "%s"', $locale, $named),
                );
            }
        }
    }

    /** @return array<string, string> flattened, keys relative to the tree */
    private function tree(string $locale): array
    {
        $file = self::DIRECTORY.'/messages.'.$locale.'.yaml';

        self::assertFileExists($file, sprintf('the Studio module has no "%s" catalogue', $locale));

        $parsed = Yaml::parseFile($file);

        if (!is_array($parsed) || !is_array($parsed[self::TREE] ?? null)) {
            return [];
        }

        return $this->flatten($parsed[self::TREE]);
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            // Cast rather than skipped: a value YAML read as an integer or a
            // date is a key that renders as its own name, which is the bug
            // this catches rather than hides.
            $flat[$path] = is_string($value) ? $value : sprintf('%s', $value);
        }

        return $flat;
    }

    /** @return list<string> */
    private function placeholders(string $value): array
    {
        if (0 === preg_match_all('/\{[a-z_]+\}/', $value, $matches) || 0 === count($matches[0])) {
            return [];
        }

        $found = $matches[0];
        sort($found);

        return $found;
    }
}
