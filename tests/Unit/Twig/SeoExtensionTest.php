<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Twig;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Locale\Repository\LocaleRepository;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Testing\Concern\CreatesStorageUrlGenerators;
use Aurora\Core\Twig\SeoExtension;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
final class SeoExtensionTest extends TestCase
{
    use CreatesStorageUrlGenerators;

    public function testAppliesTitleTemplateAndDefaults(): void
    {
        $extension = $this->makeExtension(siteName: 'MonSite', siteUrl: 'https://monsite.com');

        $seo = $extension->build(['title' => 'Page A']);

        self::assertSame('Page A — MonSite', $seo['title']);
        self::assertSame('website', $seo['type']);
        self::assertSame('summary', $seo['twitterCard']);
        self::assertFalse($seo['noindex']);
    }

    public function testReturnsSiteNameWhenNoTitlePassed(): void
    {
        $extension = $this->makeExtension(siteName: 'MonSite');

        $seo = $extension->build([]);

        self::assertSame('MonSite', $seo['title']);
    }

    public function testAbsolutizesRelativeCanonicalUrl(): void
    {
        $extension = $this->makeExtension(siteUrl: 'https://monsite.com');

        $seo = $extension->build(['canonical' => '/boutique/foo']);

        self::assertSame('https://monsite.com/boutique/foo', $seo['canonical']);
    }

    public function testKeepsAbsoluteCanonicalUrlUntouched(): void
    {
        $extension = $this->makeExtension(siteUrl: 'https://monsite.com');

        $seo = $extension->build(['canonical' => 'https://other.example/page']);

        self::assertSame('https://other.example/page', $seo['canonical']);
    }

    public function testFallsBackCanonicalToCurrentRequestPath(): void
    {
        $request = Request::create('https://monsite.com/some/path');
        $extension = $this->makeExtension(siteUrl: 'https://monsite.com', request: $request);

        $seo = $extension->build([]);

        self::assertSame('https://monsite.com/some/path', $seo['canonical']);
    }

    public function testExtractsImageUrlFromMediaEntity(): void
    {
        $media = $this->createMock(DocumentInterface::class);
        $media->method('getFilePath')->willReturn('x.jpg');

        $extension = $this->makeExtension(siteUrl: 'https://monsite.com');
        $seo = $extension->build(['image' => $media]);

        self::assertSame('https://monsite.com/uploads/x.jpg', $seo['image']);
        self::assertSame('summary_large_image', $seo['twitterCard']);
    }

    public function testExtractsImageUrlFromSerializedArray(): void
    {
        $extension = $this->makeExtension(siteUrl: 'https://monsite.com');

        $seo = $extension->build(['image' => ['publicUrl' => '/uploads/y.png', 'id' => 42]]);

        self::assertSame('https://monsite.com/uploads/y.png', $seo['image']);
    }

    public function testNoindexFlagPropagates(): void
    {
        $extension = $this->makeExtension();

        $seo = $extension->build(['noindex' => true]);

        self::assertTrue($seo['noindex']);
    }

    public function testCurrentReadsBackStoredPayload(): void
    {
        $request = Request::create('https://monsite.com/x');
        $stack = new RequestStack();
        $stack->push($request);
        $extension = $this->makeExtensionWithStack($stack);

        $extension->build(['title' => 'Stored']);
        $seo = $extension->current();

        self::assertSame('Stored — MonSite', $seo['title']);
    }

    public function testJsonLdPassedThrough(): void
    {
        $extension = $this->makeExtension();
        $payload = ['@context' => 'https://schema.org', '@type' => 'Article'];

        $seo = $extension->build(['jsonLd' => $payload]);

        self::assertSame($payload, $seo['jsonLd']);
    }

    private function makeExtension(
        string $siteName = 'MonSite',
        string $siteUrl = 'https://monsite.com',
        ?Request $request = null,
    ): SeoExtension {
        $stack = new RequestStack();
        if (null !== $request) {
            $stack->push($request);
        }

        return $this->makeExtensionWithStack($stack, $siteName, $siteUrl);
    }

    private function makeExtensionWithStack(
        RequestStack $stack,
        string $siteName = 'MonSite',
        string $siteUrl = 'https://monsite.com',
    ): SeoExtension {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getOrDefault')->willReturnCallback(
            static fn (ApplicationParameterEnum $param): string => ApplicationParameterEnum::SiteName === $param ? $siteName : $param->getDefaultValue(),
        );
        $settings->method('get')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($siteUrl): mixed {
                return match ($key) {
                    ApplicationParameterEnum::SeoTitleTemplate->value => '{title} — {siteName}',
                    ApplicationParameterEnum::SiteUrl->value => $siteUrl,
                    default => $default,
                };
            },
        );

        $localeRepo = $this->createMock(LocaleRepository::class);
        $localeRepo->method('findBy')->willReturn([]);
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('isSingleLocaleMode')->willReturn(false);

        $context = new Context($localeRepo, $settings, $localeContext, new RequestStack());

        $mediaRepo = $this->createMock(DocumentRepository::class);

        return new SeoExtension($context, $settings, $mediaRepo, $stack, $this->makeDocumentUrlGenerator());
    }
}
