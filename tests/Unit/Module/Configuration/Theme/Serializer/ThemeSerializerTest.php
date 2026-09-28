<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Serializer;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Manager\ThemeManagerInterface;
use Aurora\Module\Configuration\Theme\Serializer\ThemeSerializer;
use PHPUnit\Framework\TestCase;

final class ThemeSerializerTest extends TestCase
{
    private function makeTheme(): ThemeInterface
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getId')->willReturn(1);
        $theme->method('getSlug')->willReturn('dark');
        $theme->method('getName')->willReturn('Dark Theme');
        $theme->method('getDescription')->willReturn('A dark variant');
        $theme->method('isActive')->willReturn(true);
        $theme->method('getConfig')->willReturn(['primary' => '#000']);

        return $theme;
    }

    public function testSerializeIncludesTemplateCount(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);
        $manager->method('countTemplates')->willReturn(42);

        $result = (new ThemeSerializer($manager))->serialize($this->makeTheme());

        self::assertSame(42, $result['templateCount']);
    }

    public function testSerializeReturnsExpectedShape(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);
        $manager->method('countTemplates')->willReturn(0);

        $result = (new ThemeSerializer($manager))->serialize($this->makeTheme());

        self::assertSame(1, $result['id']);
        self::assertSame('dark', $result['slug']);
        self::assertSame('Dark Theme', $result['name']);
        self::assertSame('A dark variant', $result['description']);
        self::assertTrue($result['active']);
        self::assertSame(['primary' => '#000'], $result['config']);
        self::assertNull($result['headerLogoUrl'], 'no theme context, no logo address - and no crash');
    }
}
