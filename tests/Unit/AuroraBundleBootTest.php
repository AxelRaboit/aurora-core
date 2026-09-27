<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use Aurora\AuroraBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

/**
 * The bundle boots on a container compiled by an older version of itself.
 *
 * `cache:clear` boots the application on the container already in the cache
 * before it builds the new one. On the first deployment of 0.9.271 that
 * container was compiled by 0.9.270, where the service the bundle asked for at
 * boot was private and inlined: the command threw, the new cache was never
 * built, and the site answered 500 until it was rolled back.
 */
final class AuroraBundleBootTest extends TestCase
{
    public function testItBootsOnAContainerWithoutTheEncryptionBootstrapper(): void
    {
        $bundle = new AuroraBundle();
        $bundle->setContainer(new Container());

        $bundle->boot();

        $this->addToAssertionCount(1);
    }
}
