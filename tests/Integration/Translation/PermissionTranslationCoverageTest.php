<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Translation;

use Aurora\Core\Module\Service\PermissionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Catches the exact class of bug that bit us during the Jalon 5 privilege
 * rename: a `NavPermission('foo.bar.action')` is declared by a module
 * but no translation key `backend.permissions.names.foo.bar.action`
 * exists in the YAML (or got out of sync after a rename). At runtime
 * the privileges modal then displays the raw privilege key instead of
 * its label.
 *
 * Runs in CI (via `make ft`) so any drift is caught before it ships,
 * for both fr and en catalogues.
 */
final class PermissionTranslationCoverageTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function privilegesProvider(): iterable
    {
        self::bootKernel();
        $registry = self::getContainer()->get(PermissionRegistry::class);

        foreach ($registry->all() as $privilegeName) {
            foreach (['fr', 'en'] as $locale) {
                yield "{$privilegeName} [{$locale}]" => [$privilegeName, $locale];
            }
        }
    }

    #[DataProvider('privilegesProvider')]
    public function testEveryPrivilegeHasATranslation(string $privilegeName, string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $key = 'backend.permissions.names.'.$privilegeName;
        $translated = $translator->trans($key, [], null, $locale);

        self::assertNotSame(
            $key,
            $translated,
            sprintf(
                'Missing %s translation for privilege "%s" - expected key "%s" in a permissions YAML.',
                mb_strtoupper($locale),
                $privilegeName,
                $key,
            ),
        );

        self::assertNotEmpty(
            $translated,
            sprintf('Empty %s translation for privilege "%s" (key "%s").', mb_strtoupper($locale), $privilegeName, $key),
        );
    }
}
