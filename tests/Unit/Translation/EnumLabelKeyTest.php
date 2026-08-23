<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Translation;

use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Enum\PlanningAlertChannelEnum;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Platform\Auth\Enum\AccessRequestStatusEnum;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Every enum that names a translation key must name one that exists.
 *
 * These keys are the ones nothing else checks. A key written literally in a `.vue`
 * file is covered by {@see VueTranslationKeyTest}, and one written in Twig fails
 * visibly in review - but a key returned from PHP is a string in a method, and it
 * renders as itself on screen without anything failing anywhere.
 *
 * That is not hypothetical. The backend global search drew
 * `backend.posts.status_options.draft` on every result badge: `AppSidemenu` lives
 * in Core, built the key by concatenating a prefix it had guessed at, and the real
 * one was `backend.posts.status.draft`. Concatenated keys are invisible to the Vue
 * test too, so it shipped. The fix moved the key onto `PostStatusEnum`, next to
 * the value it names - and this is the test that keeps it honest, for that enum
 * and the nine others doing the same thing.
 *
 * Checked in every locale, because a key added to one catalogue and forgotten in
 * the other breaks only for readers of the second.
 */
final class EnumLabelKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function enums(): iterable
    {
        yield 'post status' => [PostStatusEnum::class];
        yield 'document status' => [DocumentStatusEnum::class];
        yield 'access request status' => [AccessRequestStatusEnum::class];
        yield 'user role' => [UserRoleEnum::class];
        yield 'user status' => [UserStatusEnum::class];
        yield 'user type' => [UserTypeEnum::class];
        yield 'attendee status' => [PlanningAttendeeStatusEnum::class];
        yield 'alert channel' => [PlanningAlertChannelEnum::class];
        yield 'event status' => [PlanningEventStatusEnum::class];
        yield 'calendar visibility' => [PlanningVisibilityEnum::class];
    }

    /**
     * @param class-string $enum
     */
    #[DataProvider('enums')]
    public function testEveryCaseHasALabelInEveryLocale(string $enum): void
    {
        $missing = [];

        foreach (['fr', 'en'] as $locale) {
            $labels = self::definedKeys($locale);

            foreach ($enum::cases() as $case) {
                $key = $case->getLabelKey();

                if (!isset($labels[$key])) {
                    $missing[] = sprintf('%s (%s)', $key, $locale);
                }
            }
        }

        self::assertSame([], $missing, sprintf(
            'Enum label keys that resolve to nothing - they render as the key itself, '
            ."on screen, with nothing failing:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * Every translation key defined for a locale, as a lookup.
     *
     * @return array<string, true>
     */
    private static function definedKeys(string $locale): array
    {
        static $cache = [];

        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        $keys = [];

        foreach (self::catalogues($locale) as $path) {
            $parsed = Yaml::parseFile($path);
            self::flatten(is_array($parsed) ? $parsed : [], '', $keys);
        }

        return $cache[$locale] = $keys;
    }

    /**
     * @param array<mixed>        $node
     * @param array<string, true> $keys
     */
    private static function flatten(array $node, string $prefix, array &$keys): void
    {
        foreach ($node as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::flatten($value, $path, $keys);

                continue;
            }

            if (is_string($value)) {
                $keys[$path] = true;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function catalogues(string $locale): array
    {
        $paths = [];

        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($walker as $file) {
            if ($file instanceof SplFileInfo
                && $file->isFile()
                && 1 === preg_match(sprintf('/^messages\.%s\.yaml$/', $locale), $file->getFilename())
            ) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
