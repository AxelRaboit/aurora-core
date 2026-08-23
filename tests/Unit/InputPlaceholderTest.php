<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function file_get_contents;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * Every text field carries a placeholder.
 *
 * A label says what the field is; a placeholder says what a value looks like,
 * and the two answer different questions. Sixty-two fields across twelve screens
 * had only the first, which is the kind of gap nobody reports and everybody
 * works around - you type something, it is refused, and then you learn the
 * format.
 *
 * Asserted on the attribute rather than its text. What a good placeholder says
 * is a judgement, and this is not the place for it; what is checkable is that
 * somebody made the decision at all. A field whose example is only known at
 * runtime satisfies this with a binding - the post-type custom fields read
 * `field.options?.placeholder` and fall back - which is the right answer for
 * them and would be impossible to check any other way.
 */
final class InputPlaceholderTest extends TestCase
{
    /**
     * The shared components that render a text box. `AppSelect` is absent on
     * purpose: a select's empty state is its own prop, and the codebase already
     * has `select_placeholder` for it.
     */
    private const array COMPONENTS = [
        'AppInput',
        'AppTextarea',
        'AppSearchInput',
        'AppAmountInput',
        'AppTagsInput',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function singleFileComponents(): iterable
    {
        $root = dirname(__DIR__, 2).'/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || 'vue' !== $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);

            $uses = false;
            foreach (self::COMPONENTS as $component) {
                if (str_contains($contents, '<'.$component)) {
                    $uses = true;

                    break;
                }
            }

            if (!$uses) {
                continue;
            }

            yield str_replace($root.'/', '', $path) => [$path];
        }
    }

    #[DataProvider('singleFileComponents')]
    public function testEveryTextFieldOffersAnExample(string $path): void
    {
        $contents = (string) file_get_contents($path);

        foreach (self::COMPONENTS as $component) {
            // The whole tag, attributes across as many lines as it takes. The
            // lazy quantifier stops at the first `>`, which is what closes it -
            // an attribute value containing one would break this, and none does.
            preg_match_all('/<'.$component.'\b[^>]*>/s', $contents, $matches);

            foreach ($matches[0] as $tag) {
                self::assertStringContainsString(
                    'placeholder',
                    $tag,
                    sprintf(
                        '%s: a <%s> with no placeholder. A label says what the field is; a placeholder says what a value looks like.',
                        $path,
                        $component,
                    ),
                );
            }
        }
    }

    /**
     * The provider has to find something, or the check above passes by finding
     * nothing - which is how a renamed component silently retires a test.
     */
    public function testTheScanFindsTheScreensItIsMeantTo(): void
    {
        self::assertGreaterThan(30, count([...self::singleFileComponents()]));
    }
}
