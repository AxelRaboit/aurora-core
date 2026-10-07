<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * What the media library fixture promises, and only half kept.
 *
 * **It skips a definition whose file is missing instead of failing**, so that
 * a rename does not break `make demo`. The price is that a smaller demo does
 * not announce itself: it is this test's job to say so.
 *
 * It only said so for one half. It read the library's `'src'` keys and
 * ignored the documents' `'file'` keys, which resolved their root four levels
 * above the repository instead of two. Fifteen documents out of thirty-three
 * therefore had no file, no size and no thumbnail, and nothing said so for
 * months.
 *
 * Hence the two guarantees below: the root, then the files.
 */
final class DemoFixtureFilesExistTest extends TestCase
{
    /**
     * The sources the fixture names without shipping them, on purpose.
     *
     * Just one, and it earns it: thirty seconds of video weigh eighteen
     * megabytes in a public repository, and a video necessarily has a subject
     * - the previous one showed a park - which is exactly what the demo media
     * library is kept away from. The rest are flat colors.
     */
    private const array DELIBERATELY_ABSENT = [
        'videos/sample-30s-720p.mp4',
    ];

    /**
     * Both code paths target the same root, and it exists.
     *
     * **This is the guarantee that was missing**, and it is sturdier than
     * counting files: `dirname(__DIR__, 4)` left the repository for a folder
     * that exists on no machine, which no file list could reveal since they
     * were all missing together.
     */
    public function testEveryPathTheFixtureBuildsLandsInsideTheRepository(): void
    {
        preg_match_all(
            "/dirname\(__DIR__, (\d+)\)\.'\/test_files'/",
            $this->fixtureSource(),
            $matches,
        );

        self::assertNotEmpty($matches[1], 'La fixture ne construit plus aucun chemin vers test_files.');

        $wrong = [];

        // The folder the fixture counts from, not a relative path: `dirname`
        // does not resolve the `..` it is given.
        $fixtureDirectory = dirname(__DIR__, 4).'/fixtures/Ged';

        foreach ($matches[1] as $levels) {
            $root = dirname($fixtureDirectory, (int) $levels);

            if (!is_dir($root.'/test_files')) {
                $wrong[] = sprintf('dirname(__DIR__, %s) -> %s', $levels, $root);
            }
        }

        self::assertSame([], $wrong, sprintf(
            'La fixture résout une racine qui ne contient pas test_files/ : %s. Elle passera alors chaque source en silence, et la médiathèque de démonstration sortira faite de lignes vides.',
            implode(', ', $wrong),
        ));
    }

    /**
     * Every named file is shipped, or can be drawn.
     *
     * That is the fixture's contract, written here rather than implied: a
     * missing source is only acceptable if the definition carries a width, in
     * which case a flat color takes its place. Without a width - the case of a
     * PDF - a missing source leaves a row without a file, which is refused.
     */
    public function testEveryNamedSourceIsShippedOrCanBeDrawn(): void
    {
        $source = $this->fixtureSource();
        $root = dirname(__DIR__, 4).'/test_files/';

        preg_match_all("/'(src|file)' => '([^']+)'([^\\]]*)/", $source, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'La fixture ne nomme plus aucune source.');

        $missing = [];

        foreach ($matches as [, , $relative, $rest]) {
            // A source path carries a folder. A bare name is that of a file
            // the fixture writes itself - the past versions of a document,
            // drawn on the spot - and not of a source to read.
            if (!str_contains($relative, '/')) {
                continue;
            }

            if (in_array($relative, self::DELIBERATELY_ABSENT, true) || is_file($root.$relative)) {
                continue;
            }

            // A flat color stands in for the source when the definition gives its size.
            if (str_contains($rest, "'w' =>")) {
                continue;
            }

            $missing[] = $relative;
        }

        self::assertSame([], $missing, sprintf(
            'La fixture nomme des fichiers qui ne sont ni dans test_files/ ni dessinables faute de largeur : %s. Ils donneront des documents sans pièce jointe, en silence.',
            implode(', ', $missing),
        ));
    }

    private function fixtureSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/fixtures/Ged/GedDemoFixtures.php');
        self::assertIsString($source);

        return $source;
    }
}
