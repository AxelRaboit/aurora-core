<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Frontend\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Locale\Entity\LocaleInterface;
use Aurora\Core\Locale\Repository\LocaleRepository;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
final class ContextTest extends TestCase
{
    private LocaleRepository $localeRepository;
    private SettingRepository $settingRepository;
    private LocaleContextInterface $localeContext;

    protected function setUp(): void
    {
        $this->localeRepository = $this->createMock(LocaleRepository::class);
        $this->settingRepository = $this->createMock(SettingRepository::class);
        $this->localeContext = $this->createMock(LocaleContextInterface::class);
        // Default = multi-locale mode (no filtering on `activeLocales`).
        $this->localeContext->method('isSingleLocaleMode')->willReturn(false);
    }

    private function makeLocale(string $code, bool $isDefault = false): LocaleInterface
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('getCode')->willReturn($code);
        $locale->method('isDefault')->willReturn($isDefault);

        return $locale;
    }

    private function makeContext(): Context
    {
        return new Context($this->localeRepository, $this->settingRepository, $this->localeContext, new RequestStack());
    }

    public function testActiveLocalesQueriesTheRepositoryOncePerInstance(): void
    {
        $en = $this->makeLocale('en', true);
        $fr = $this->makeLocale('fr');

        // The cache invariant — the repo is hit only on the first call,
        // subsequent calls hit the in-memory list.
        $this->localeRepository->expects(self::once())
            ->method('findBy')
            ->willReturn([$en, $fr]);

        $context = $this->makeContext();
        $first = $context->activeLocales();
        $second = $context->activeLocales();

        self::assertSame($first, $second);
        self::assertCount(2, $first);
    }

    public function testSingleLocaleModeStripsLocalesOtherThanTheDefault(): void
    {
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('isSingleLocaleMode')->willReturn(true);
        $localeContext->method('getDefaultLocale')->willReturn('fr');

        $this->localeRepository->method('findBy')->willReturn([
            $this->makeLocale('en'),
            $this->makeLocale('fr'),
            $this->makeLocale('es'),
        ]);

        $context = new Context($this->localeRepository, $this->settingRepository, $localeContext, new RequestStack());

        $codes = array_map(static fn (LocaleInterface $l) => $l->getCode(), $context->activeLocales());
        self::assertSame(['fr'], $codes);
    }

    public function testActiveLocaleCodesReturnsBareStringList(): void
    {
        $this->localeRepository->method('findBy')->willReturn([
            $this->makeLocale('en', true),
            $this->makeLocale('fr'),
        ]);

        self::assertSame(['en', 'fr'], $this->makeContext()->activeLocaleCodes());
    }

    public function testDefaultLocaleReturnsTheLocaleFlaggedAsDefault(): void
    {
        $this->localeRepository->method('findBy')->willReturn([
            $this->makeLocale('en'),
            $this->makeLocale('fr', true),
            $this->makeLocale('es'),
        ]);

        self::assertSame('fr', $this->makeContext()->defaultLocale());
    }

    public function testDefaultLocaleFallsBackToTheSettingWhenNoLocaleIsFlagged(): void
    {
        // None of the active locales has isDefault=true — the setting is
        // the next source of truth before the enum default kicks in.
        $this->localeRepository->method('findBy')->willReturn([
            $this->makeLocale('en'),
            $this->makeLocale('fr'),
        ]);
        $this->settingRepository->expects(self::atLeastOnce())
            ->method('get')
            ->with(ApplicationParameterEnum::DefaultLocale->value, self::anything())
            ->willReturn('de');

        self::assertSame('de', $this->makeContext()->defaultLocale());
    }

    public function testDefaultLocaleCachesAcrossCalls(): void
    {
        $this->localeRepository->expects(self::once())->method('findBy')->willReturn([
            $this->makeLocale('en', true),
        ]);

        $context = $this->makeContext();
        $context->defaultLocale();
        $context->defaultLocale();
        $context->defaultLocale();
        // No assertion beyond the repository call count — implicit pass
        // via the `expects(self::once())` constraint above.
        self::assertSame('en', $context->defaultLocale());
    }

    public function testIsLocaleActiveMatchesAnyActiveCode(): void
    {
        $this->localeRepository->method('findBy')->willReturn([
            $this->makeLocale('en'),
            $this->makeLocale('fr'),
        ]);

        $context = $this->makeContext();

        self::assertTrue($context->isLocaleActive('fr'));
        self::assertFalse($context->isLocaleActive('de'));
    }

    public function testSettingDelegatesToTheRepositoryWithDefault(): void
    {
        $this->settingRepository->expects(self::once())
            ->method('get')
            ->with('custom_key', 'fallback')
            ->willReturn('value');

        self::assertSame('value', $this->makeContext()->setting('custom_key', 'fallback'));
    }

    public function testSiteNameReturnsTheConfiguredValue(): void
    {
        $this->settingRepository->expects(self::once())
            ->method('getOrDefault')
            ->with(ApplicationParameterEnum::SiteName)
            ->willReturn('Aurora Test');

        self::assertSame('Aurora Test', $this->makeContext()->siteName());
    }

    public function testSiteDescriptionPassesThroughTheNullDefault(): void
    {
        // siteDescription expects to receive whatever the repo returns,
        // including null — keep the contract loose for unset values.
        $this->settingRepository->expects(self::atLeastOnce())
            ->method('get')
            ->with(ApplicationParameterEnum::SiteDescription->value, null)
            ->willReturn(null);

        self::assertNull($this->makeContext()->siteDescription());
    }

    public function testSiteUrlStripsTrailingSlash(): void
    {
        $this->settingRepository->expects(self::atLeastOnce())
            ->method('get')
            ->with(ApplicationParameterEnum::SiteUrl->value, self::anything())
            ->willReturn('https://aurora.test///');

        self::assertSame('https://aurora.test', $this->makeContext()->siteUrl());
    }

    public function testSiteUrlFallsBackToLocalhostWhenUnset(): void
    {
        $this->settingRepository->method('get')->willReturn(null);

        self::assertSame('http://localhost', $this->makeContext()->siteUrl());
    }

    public function testHomepagePostIdReturnsNullWhenSettingIsEmpty(): void
    {
        $this->settingRepository->method('get')->willReturn('');

        self::assertNull($this->makeContext()->homepagePostId());
    }

    public function testHomepagePostIdParsesAsInt(): void
    {
        $this->settingRepository->expects(self::atLeastOnce())
            ->method('get')
            ->with(ApplicationParameterEnum::HomepagePostId->value, self::anything())
            ->willReturn('42');

        self::assertSame(42, $this->makeContext()->homepagePostId());
    }
}
