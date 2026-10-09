<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Version;

use Aurora\Core\Version\AppVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AppVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aurora-version-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testReadsTheTagWrittenByTheDeployment(): void
    {
        file_put_contents($this->directory.'/VERSION', "v3.8.4\n");

        self::assertSame('v3.8.4', new AppVersion($this->directory)->current());
    }

    /** Read on every call: the file changes under a running process. */
    public function testSeesANewDeploymentWithoutRestarting(): void
    {
        $version = new AppVersion($this->directory);
        file_put_contents($this->directory.'/VERSION', 'v3.8.3');
        self::assertSame('v3.8.3', $version->current());

        file_put_contents($this->directory.'/VERSION', 'v3.8.4');
        self::assertSame('v3.8.4', $version->current());
    }

    public function testADevelopmentCheckoutIsDev(): void
    {
        self::assertSame('dev', new AppVersion($this->directory)->current());

        file_put_contents($this->directory.'/VERSION', "  \n");
        self::assertSame('dev', new AppVersion($this->directory)->current());
    }
}
