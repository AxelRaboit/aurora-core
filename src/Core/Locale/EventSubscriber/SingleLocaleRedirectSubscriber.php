<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\EventSubscriber;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;

/**
 * When single-language mode is on, redirects any URL prefixed with a locale
 * code ≠ default to its equivalent on the default locale (301).
 *
 * Runs after LocaleSubscriber (priority 20) and before Symfony's
 * RouterListener (priority 32 → runs earlier, but priority 32 < 20? No: on
 * KernelEvents::REQUEST, higher priorities run first. So 18 < 20: this
 * subscriber runs right after LocaleSubscriber. The RouterListener runs with
 * priority 32 → BEFORE us, but with priority 16 → after, depending on the
 * version. It does not matter: we only work on the Request's raw path,
 * independently of the router.
 */
final readonly class SingleLocaleRedirectSubscriber implements EventSubscriberInterface
{
    private const string LOCALE_PREFIX_PATTERN = '#^/([a-z]{2})(/.*|$)#';

    public function __construct(
        private LocaleContextInterface $localeContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 18]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->localeContext->isSingleLocaleMode()) {
            return;
        }

        $request = $event->getRequest();
        $pathInfo = $request->getPathInfo();

        if (1 !== preg_match(self::LOCALE_PREFIX_PATTERN, $pathInfo, $matches)) {
            return;
        }

        $urlLocale = $matches[1];
        $defaultLocale = $this->localeContext->getDefaultLocale();

        if ($urlLocale === $defaultLocale) {
            return;
        }

        // Only locale codes known to the bundle are touched, to avoid catching
        // by mistake paths like `/ab/foo` that are not locales.
        if (!in_array($urlLocale, $this->localeContext->getAllLocales(), true)) {
            return;
        }

        $remainder = '' === $matches[2] ? '/' : $matches[2];
        $newPath = '/'.$defaultLocale.$remainder;

        $queryString = $request->getQueryString();
        $target = $request->getBaseUrl().$newPath.(null !== $queryString ? '?'.$queryString : '');

        $event->setResponse(new RedirectResponse($target, 301));
    }
}
