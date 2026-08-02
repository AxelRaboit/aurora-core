<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Validator\Constraint;

/**
 * Every constraint message in this module was a bare key —
 * `post_types.errors.slug_required` and the like. Nothing resolves those:
 * PayloadValidator hands messages back untranslated by design, and the Vue
 * layer that does translate them reads a catalogue built only from
 * `messages.*.yaml`, where the keys live under `backend.`. Every failed
 * validation therefore showed the user a raw key instead of a sentence.
 *
 * This walks the DTOs rather than listing them, so a new one is covered the
 * day it is written.
 */
final class ValidationMessageKeyTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function dtoProvider(): iterable
    {
        $directory = dirname(__DIR__, 5).'/src/Module/Editorial';
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
    public function testEveryConstraintMessageIsAKeyTheFrontCanResolve(string $class): void
    {
        $messages = self::constraintMessages($class);

        if ([] === $messages) {
            // Sub-DTOs carry no constraints of their own: only the root DTO a
            // controller consumes is validated. Nothing to check here.
            self::assertTrue(true);

            return;
        }

        foreach ($messages as $message) {
            self::assertStringStartsWith(
                'backend.',
                $message,
                sprintf(
                    'The message "%s" in %s cannot be resolved: only keys under `backend.` reach the JS catalogue, '
                    ."so this one would render to the user as its own key.\n",
                    $message,
                    $class,
                ),
            );
        }
    }

    /** @return list<string> */
    private static function constraintMessages(string $class): array
    {
        $messages = [];
        $constructor = (new ReflectionClass($class))->getConstructor();

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
