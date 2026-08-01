<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

final class TranslationConsistencyTest extends TestCase
{
    private const PLACEHOLDER_EXCEPTIONS = [
        // ICU plural syntax — accolades imbriquées contiennent du texte traduit, pas des placeholders
        'photo.galleries.usage.item_count',
    ];

    private const PARITY_EXCEPTIONS = [
        // Laisse vide pour l'instant — on ajoutera au fur et à mesure
    ];

    /** @return list<array{string, array<string, mixed>, array<string, mixed>}> */
    public static function translationPairsProvider(): array
    {
        $srcDir = dirname(__DIR__, 3).'/src';
        $pairs = [];

        // Discovered by walking src/ rather than by a fixed list of glob
        // depths. The previous globs stopped at src/Module/*/translations,
        // which silently skipped 8 of the 14 translation directories — every
        // sub-module one (Dev/Audit, Platform/User, Configuration/Theme, …).
        // Audit's messages.en.yaml sat half-filled with French text for that
        // exact reason: no test ever looked at it.
        $dirs = [];
        $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($walker as $file) {
            if ('messages.fr.yaml' === $file->getFilename()) {
                $dirs[] = \dirname($file->getPathname());
            }
        }
        sort($dirs);

        foreach ($dirs as $dir) {
            $frFile = $dir.'/messages.fr.yaml';
            $enFile = $dir.'/messages.en.yaml';

            if (!file_exists($enFile)) {
                continue;
            }

            // Keyed by the full path relative to src/, not by module name:
            // Dev/Audit and Dev/MountPoint both reduce to "Dev" and would
            // overwrite each other in this array, so only one of them would
            // ever be asserted on.
            $label = str_replace($srcDir.'/', '', $dir);

            $pairs[$label] = [
                $label,
                Yaml::parseFile($frFile) ?? [],
                Yaml::parseFile($enFile) ?? [],
            ];
        }

        return array_values($pairs);
    }

    /**
     * @param array<string, mixed> $fr
     * @param array<string, mixed> $en
     */
    #[DataProvider('translationPairsProvider')]
    public function testFrEnKeyParity(string $module, array $fr, array $en): void
    {
        $flatFr = $this->flattenKeys($fr);
        $flatEn = $this->flattenKeys($en);

        $missingInEn = array_diff_key($flatFr, $flatEn);
        $missingInFr = array_diff_key($flatEn, $flatFr);

        foreach (self::PARITY_EXCEPTIONS as $exception) {
            unset($missingInEn[$exception], $missingInFr[$exception]);
        }

        self::assertEmpty(
            $missingInEn,
            sprintf(
                '[%s] Keys present in FR but missing in EN: %s',
                $module,
                implode(', ', array_keys($missingInEn)),
            ),
        );

        self::assertEmpty(
            $missingInFr,
            sprintf(
                '[%s] Keys present in EN but missing in FR: %s',
                $module,
                implode(', ', array_keys($missingInFr)),
            ),
        );
    }

    /**
     * @param array<string, mixed> $fr
     * @param array<string, mixed> $en
     */
    #[DataProvider('translationPairsProvider')]
    public function testNoEmptyValues(string $module, array $fr, array $en): void
    {
        $flatFr = $this->flattenKeys($fr);
        $flatEn = $this->flattenKeys($en);

        $emptyFr = array_filter($flatFr, static fn (mixed $value): bool => '' === $value || null === $value);
        $emptyEn = array_filter($flatEn, static fn (mixed $value): bool => '' === $value || null === $value);

        self::assertEmpty(
            $emptyFr,
            sprintf('[%s] Empty or null values in FR: %s', $module, implode(', ', array_keys($emptyFr))),
        );

        self::assertEmpty(
            $emptyEn,
            sprintf('[%s] Empty or null values in EN: %s', $module, implode(', ', array_keys($emptyEn))),
        );
    }

    /**
     * @param array<string, mixed> $fr
     * @param array<string, mixed> $en
     */
    #[DataProvider('translationPairsProvider')]
    public function testPlaceholderConsistency(string $module, array $fr, array $en): void
    {
        $flatFr = $this->flattenKeys($fr);
        $flatEn = $this->flattenKeys($en);

        $commonKeys = array_intersect_key($flatFr, $flatEn);

        foreach ($commonKeys as $key => $_) {
            if (in_array($key, self::PLACEHOLDER_EXCEPTIONS, true)) {
                continue;
            }

            $frValue = (string) $flatFr[$key];
            $enValue = (string) $flatEn[$key];

            $frPlaceholders = $this->extractPlaceholders($frValue);
            $enPlaceholders = $this->extractPlaceholders($enValue);

            sort($frPlaceholders);
            sort($enPlaceholders);

            self::assertSame(
                $enPlaceholders,
                $frPlaceholders,
                sprintf(
                    '[%s] Placeholder mismatch for key "%s": FR has {%s}, EN has {%s}',
                    $module,
                    $key,
                    implode('}, {', $frPlaceholders),
                    implode('}, {', $enPlaceholders),
                ),
            );
        }
    }

    /**
     * Detects pre-escaped {'@'} in YAML source files.
     * DumpJsTranslationsCommand escapes bare @ → {'@'} automatically.
     * If a YAML source already contains {'@'}, the command double-escapes it
     * to {'{'@'}'} which causes a vue-i18n SyntaxError at runtime.
     *
     * @param array<string, mixed> $fr
     * @param array<string, mixed> $en
     */
    #[DataProvider('translationPairsProvider')]
    public function testNoPreEscapedAt(string $module, array $fr, array $en): void
    {
        $flatFr = $this->flattenKeys($fr);
        $flatEn = $this->flattenKeys($en);

        $violationsFr = array_filter($flatFr, static fn (mixed $v): bool => is_string($v) && str_contains($v, "{'@'}"));
        $violationsEn = array_filter($flatEn, static fn (mixed $v): bool => is_string($v) && str_contains($v, "{'@'}"));

        self::assertEmpty(
            $violationsFr,
            sprintf(
                '[%s] FR YAML contains pre-escaped {\'@\'} — use bare @ instead, DumpJsTranslationsCommand handles escaping: %s',
                $module,
                implode(', ', array_keys($violationsFr)),
            ),
        );

        self::assertEmpty(
            $violationsEn,
            sprintf(
                '[%s] EN YAML contains pre-escaped {\'@\'} — use bare @ instead, DumpJsTranslationsCommand handles escaping: %s',
                $module,
                implode(', ', array_keys($violationsEn)),
            ),
        );
    }

    /** @return list<string> */
    private function extractPlaceholders(string $value): array
    {
        preg_match_all('/\{([^}]+)\}/', $value, $matches);

        return $matches[1];
    }

    /**
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     */
    private function flattenKeys(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $result += $this->flattenKeys($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}
