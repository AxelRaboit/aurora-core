<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Yaml\Yaml;

/**
 * Every constraint message in this module was once a bare key -
 * `post_types.errors.slug_required` and the like. Nothing resolves those:
 * PayloadValidator hands messages back untranslated by design, and the layer
 * that does translate them looks the key up in the catalogue. Every failed
 * validation therefore showed the user a raw key instead of a sentence.
 *
 * The check is that the key **resolves**, not that it carries a particular
 * prefix. An earlier version of this test asserted `backend.`, which was true
 * of every DTO at the time and stopped being true the moment a public form
 * arrived - a rule read off a coincidence, which then blocked the correct
 * work. Looking the key up in the catalogue asks the real question and
 * catches a typo besides.
 *
 * The DTOs are walked rather than listed, so a new one is covered the day it
 * is written.
 */
final class ValidationMessageKeyTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function dtoProvider(): iterable
    {
        $directory = self::moduleDir();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Input.php')) {
                continue;
            }

            $class = 'Aurora\Module\Editorial'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                mb_substr($file->getPathname(), mb_strlen($directory)),
            );

            if (class_exists($class)) {
                yield $class => [$class];
            }
        }
    }

    #[DataProvider('dtoProvider')]
    public function testEveryConstraintMessageIsAKeyThatResolves(string $class): void
    {
        $messages = self::constraintMessages($class);

        if ([] === $messages) {
            // Sub-DTOs carry no constraints of their own: only the root DTO a
            // controller consumes is validated. Nothing to check here.
            self::assertTrue(true);

            return;
        }

        $catalogue = self::catalogue();

        foreach ($messages as $message) {
            self::assertTrue(
                self::resolves($message, $catalogue),
                sprintf(
                    'The message "%s" in %s does not resolve to anything in the module\'s translations, '
                    ."so a failed validation would show the user this key instead of a sentence.\n",
                    $message,
                    $class,
                ),
            );
        }
    }

    /** @param array<string, mixed> $catalogue */
    private static function resolves(string $key, array $catalogue): bool
    {
        $node = $catalogue;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }

            $node = $node[$segment];
        }

        return is_string($node) && '' !== $node;
    }

    /** @return array<string, mixed> */
    private static function catalogue(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(self::moduleDir().'/translations/messages.en.yaml');

        return $parsed;
    }

    private static function moduleDir(): string
    {
        return dirname(__DIR__, 5).'/src/Module/Editorial';
    }

    /** @return list<string> */
    private static function constraintMessages(string $class): array
    {
        $messages = [];
        $constructor = new ReflectionClass($class)->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            foreach ($parameter->getAttributes() as $attribute) {
                if (!is_subclass_of($attribute->getName(), Constraint::class)) {
                    continue;
                }

                foreach ($attribute->getArguments() as $name => $value) {
                    if (is_string($value) && str_contains((string) $name, 'essage')) {
                        $messages[] = $value;
                    }
                }
            }
        }

        return $messages;
    }
}
