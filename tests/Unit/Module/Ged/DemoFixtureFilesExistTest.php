<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged;

use PHPUnit\Framework\TestCase;

/**
 * Every source file the GED demo fixture names has to be on disk.
 *
 * The fixture skips a definition whose file is missing rather than failing, so
 * a renamed or removed asset does not break `make demo` - it just quietly
 * produces a smaller demo. Nothing would say so. This does.
 */
final class DemoFixtureFilesExistTest extends TestCase
{
    public function testEveryDemoSourceFileIsPresent(): void
    {
        $root = dirname(__DIR__, 4);
        $fixture = $root.'/src/Module/Ged/DataFixtures/GedDemoFixtures.php';
        $source = file_get_contents($fixture);
        self::assertIsString($source);

        preg_match_all("/'src' => '([^']+)'/", $source, $matches);
        self::assertNotEmpty($matches[1], 'No demo source file found in the fixture.');

        $missing = [];
        foreach ($matches[1] as $relative) {
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
