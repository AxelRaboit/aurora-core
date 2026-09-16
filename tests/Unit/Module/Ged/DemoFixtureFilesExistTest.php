<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged;

use PHPUnit\Framework\TestCase;

use function in_array;

/**
 * Every source file the GED demo fixture names has to be on disk.
 *
 * The fixture skips a definition whose file is missing rather than failing, so
 * a renamed or removed asset does not break `make demo` - it just quietly
 * produces a smaller demo. Nothing would say so. This does.
 */
final class DemoFixtureFilesExistTest extends TestCase
{
    /**
     * Sources the fixture names on purpose without shipping them.
     *
     * Only one, and it earns the exception. A thirty-second clip is eighteen
     * megabytes in a public repository, and a video necessarily has a subject
     * - the one that was there showed a park - which is precisely what the
     * demo library is kept free of. Every other source is a flat gradient.
     *
     * The fixture's own fallback then files the row without bytes, and that
     * is the case worth showing anyway: a document with nothing attached is
     * what the upload flow is tested against.
     */
    private const array DELIBERATELY_ABSENT = [
        'videos/sample-30s-720p.mp4',
    ];

    public function testEveryDemoSourceFileIsPresent(): void
    {
        $root = dirname(__DIR__, 4);
        $fixture = $root.'/fixtures/Ged/GedDemoFixtures.php';
        $source = file_get_contents($fixture);
        self::assertIsString($source);

        preg_match_all("/'src' => '([^']+)'/", $source, $matches);
        self::assertNotEmpty($matches[1], 'No demo source file found in the fixture.');

        $missing = [];
        foreach ($matches[1] as $relative) {
            if (in_array($relative, self::DELIBERATELY_ABSENT, true)) {
                continue;
            }

            if (!is_file($root.'/test_files/'.$relative)) {
                $missing[] = $relative;
            }
        }

        self::assertSame([], $missing, sprintf(
            'The GED demo fixture names files that are not in test_files/: %s. The fixture skips them silently, so the demo would simply come out short.',
            implode(', ', $missing),
        ));
    }
}
