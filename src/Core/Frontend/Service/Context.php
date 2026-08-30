<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\Service;

use Aurora\Core\Locale\Entity\LocaleInterface;
use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Locale\Repository\LocaleRepository;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Doctrine\Common\Collections\Order;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Aggregates site-wide configuration used by public-facing controllers.
 */
final class Context
{
    /**
     * What `site_url` used to ship with, before it was seeded empty. Kept so
     * the installations that already carry it are not stuck announcing a host
     * nobody can reach - see siteUrl().
     */
    private const string LEGACY_PLACEHOLDER_SITE_URL = 'http://localhost';

    /** @var list<LocaleInterface>|null */
    private ?array $cachedLocales = null;

    private ?LocaleInterface $cachedDefault = null;

    public function __construct(
        private readonly LocaleRepository $localeRepository,
        private readonly SettingRepository $settingRepository,
        private readonly LocaleContextInterface $localeContext,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return list<LocaleInterface> */
    public function activeLocales(): array
    {
        if (null === $this->cachedLocales) {
            $locales = $this->localeRepository->findBy(
                ['isActive' => true],
                ['position' => Order::Ascending->value, 'code' => Order::Ascending->value],
            );

            if ($this->localeContext->isSingleLocaleMode()) {
                $defaultCode = $this->localeContext->getDefaultLocale();
                $locales = array_values(array_filter(
                    $locales,
                    static fn (LocaleInterface $locale): bool => $locale->getCode() === $defaultCode,
                ));
            }

            $this->cachedLocales = $locales;
        }

        return $this->cachedLocales;
    }

    /** @return list<string> */
    public function activeLocaleCodes(): array
    {
        return array_map(static fn (LocaleInterface $locale): string => $locale->getCode(), $this->activeLocales());
    }

    public function defaultLocale(): string
    {
        if (!$this->cachedDefault instanceof LocaleInterface) {
            foreach ($this->activeLocales() as $locale) {
                if ($locale->isDefault()) {
                    $this->cachedDefault = $locale;
                    break;
                }
            }
        }

        return $this->cachedDefault?->getCode()
            ?? $this->settingRepository->get(ApplicationParameterEnum::DefaultLocale->value, LocaleEnum::default()->value)
            ?? LocaleEnum::default()->value;
    }

    public function isLocaleActive(string $code): bool
    {
        return in_array($code, $this->activeLocaleCodes(), true);
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        return $this->settingRepository->get($key, $default);
    }

    public function siteName(): string
    {
        return $this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteName);
    }

    public function siteDescription(): ?string
    {
        return $this->setting(ApplicationParameterEnum::SiteDescription->value, null);
    }

    /**
     * The origin every public absolute URL is built on - canonical tags,
     * hreflang, og:image, the sitemap.
     *
     * The setting wins when an administrator has actually set it: pinning one
     * host is the whole point of having it, and a site reachable at both
     * `example.com` and `www.example.com` needs its canonical URLs to agree
     * on one of them.
     *
     * But the parameter can be unset, or still carry the `http://localhost` it
     * used to be seeded with, which nobody chose. Taking that at face value is
     * how a production deploy ends up telling search engines that the canonical
     * address of every page is a host they cannot reach - a failure with no
     * symptom anywhere in the application.
     *
     * Unset, there are two better answers and they are tried in order: the
     * request's own origin, which is exact on a live site, then the routing
     * context, which the framework fills from DEFAULT_URI and which is the only
     * thing a console command has. A worker rendering a canonical tag or a
     * sitemap entry therefore uses the host the deployment already declared,
     * instead of a placeholder.
     */
    public function siteUrl(): string
    {
        $configured = mb_rtrim($this->setting(ApplicationParameterEnum::SiteUrl->value, '') ?? '', '/');

        if ('' !== $configured && self::LEGACY_PLACEHOLDER_SITE_URL !== $configured) {
            return $configured;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            return $request->getSchemeAndHttpHost();
        }

        // No request to learn from - a console command or a Messenger worker.
        $context = $this->urlGenerator->getContext();
        $host = $context->getHost();

        if ('' !== $host) {
            $scheme = $context->getScheme();
            $port = 'https' === $scheme ? $context->getHttpsPort() : $context->getHttpPort();
            $suffix = in_array($port, [80, 443], true) ? '' : ':'.$port;

            return $scheme.'://'.$host.$suffix;
        }

        // Nothing configured anywhere. Returning an empty string would produce
        // URLs that are wrong in a harder-to-spot way than an obvious host.
        return '' !== $configured ? $configured : self::LEGACY_PLACEHOLDER_SITE_URL;
    }

    public function homepagePostId(): ?int
    {
        $value = $this->setting(ApplicationParameterEnum::HomepagePostId->value, '');

        return null !== $value && '' !== $value ? (int) $value : null;
    }
}
