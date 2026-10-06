<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * This repository is public: nothing in it names real infrastructure.
 *
 * **The rule existed, and nothing enforced it.** The identifier of a real
 * Google Drive folder got into the fixtures and into a test, copied from a
 * settings screen during a work session, and it stayed there until someone
 * noticed it - after it had been pushed, tagged and released.
 *
 * A folder identifier opens nothing on its own: you have to be shared on it
 * to read it. So it is not a leaked secret, it is a trace: it names
 * infrastructure that belongs to someone, in a repository anyone can read.
 *
 * The test does not look for "a secret", which would be endless. It looks for
 * the two precise shapes that already got through, and it is meant to grow
 * when a third one gets through.
 */
final class NoRealInfrastructureInTheRepositoryTest extends TestCase
{
    /**
     * The directories that describe the product, as opposed to those that
     * configure it.
     *
     * `config/` and `.env` carry deployment values by nature; what is watched
     * here is the code, the fixtures and the tests, where a real value has no
     * reason to appear.
     */
    private const array SCANNED = ['src', 'fixtures', 'tests', 'tools'];

    /**
     * What looks like a Google identifier, and the shapes that are accepted.
     *
     * A Drive identifier is at least twenty-five characters of meaningless
     * base64. Ours have a meaning - they read as words - so they trigger
     * nothing.
     */
    public function testNoGoogleDriveFolderIdentifierIsCommitted(): void
    {
        $found = [];
        $root = dirname(__DIR__, 3);

        foreach (self::SCANNED as $scannedDirectory) {
            foreach ($this->filesIn($root.'/'.$scannedDirectory) as $file) {
                $source = (string) file_get_contents($file->getPathname());

                // A Drive identifier as Google mints them: at least
                // twenty-five characters, and at least one digit AND one
                // uppercase AND one lowercase letter, which a French word lacks.
                preg_match_all("/'([A-Za-z0-9_-]{25,})'/", $source, $matches);

                foreach ($matches[1] as $candidate) {
                    if ($this->looksGenerated($candidate)) {
                        $found[] = sprintf('%s → %s', $file->getFilename(), $candidate);
                    }
                }
            }
        }

        self::assertSame([], $found, sprintf(
            "Des identifiants d'apparence réelle sont dans le dépôt, qui est public : %s.\nUn identifiant de dossier n'ouvre rien à lui seul, mais il nomme une infrastructure qui appartient à quelqu'un. Utilisez un nom qui se lit, comme « dossier-de-demonstration-aurora ».",
            implode(', ', $found),
        ));
    }

    /** @return list<SplFileInfo> */
    private function filesIn(string $directory): array
    {
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), ['php', 'mjs', 'js'], true)) {
                continue;
            }

            // `tools/` carries its own dependencies: what is found there
            // belongs to others, and the checksums Composer writes there look
            // like everything being searched for.
            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $found[] = $file;
        }

        return $found;
    }

    /**
     * A string minted by a machine, not a name written by someone.
     *
     * All three character classes together, and no separator that would make
     * words: "dossier-de-demonstration-aurora" has neither digit nor
     * uppercase, "1bbo9FyKEudNl7oeyPX-R5uX41_cPZLk3" has all three.
     */
    private function looksGenerated(string $candidate): bool
    {
        return 1 === preg_match('/[a-z]/', $candidate)
            && 1 === preg_match('/[A-Z]/', $candidate)
            && 1 === preg_match('/[0-9]/', $candidate)
            && !str_contains($candidate, ' ');
    }
}
