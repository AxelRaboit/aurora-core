<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\View;

use Aurora\Module\Configuration\Theme\Entity\Theme;
use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Serializer\ThemeSerializerInterface;
use Aurora\Module\Configuration\Theme\View\ThemesViewBuilder;
use PHPUnit\Framework\TestCase;

final class ThemesViewBuilderTest extends TestCase
{
    public function testIndexViewReturnsSerializedThemes(): void
    {
        $repository = $this->createStub(ThemeRepository::class);
        $repository->method('findAll')->willReturn([new Theme(), new Theme(), new Theme()]);

        $serializer = $this->createStub(ThemeSerializerInterface::class);
        $serializer->method('serialize')->willReturn(['slug' => 'dark']);

        $view = (new ThemesViewBuilder($repository, $serializer))->indexView();

        self::assertArrayHasKey('themes', $view);
        self::assertCount(3, $view['themes']);
    }

    public function testIndexViewCarriesTheFontChoices(): void
    {
        $repository = $this->createStub(ThemeRepository::class);
        $repository->method('findAll')->willReturn([]);

        $view = (new ThemesViewBuilder($repository, $this->createStub(ThemeSerializerInterface::class)))->indexView();

        self::assertSame(ThemeFontEnum::choices(), $view['fonts']);
    }

    public function testIndexViewWithEmptyRepository(): void
    {
        $repository = $this->createStub(ThemeRepository::class);
        $repository->method('findAll')->willReturn([]);

        $serializer = $this->createStub(ThemeSerializerInterface::class);

        $view = (new ThemesViewBuilder($repository, $serializer))->indexView();

        self::assertSame([], $view['themes']);
    }
}
