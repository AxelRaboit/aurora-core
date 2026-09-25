<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Locale\EventSubscriber;

use Aurora\Core\Locale\EventSubscriber\LocaleSubscriber;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Which language a request is translated in.
 *
 * Two sources disagree on a public page: its address, and the session of
 * whoever last used the back office. The address has to win, or a Spanish page
 * prints its template strings in the back office's French.
 */
final class LocaleSubscriberTest extends TestCase
{
    public function testAPublicPageIsTranslatedInTheLanguageOfItsAddress(): void
    {
        $request = $this->request(session: 'fr', route: 'es');

        $this->subscriber()->onKernelRequest($this->event($request));

        self::assertSame('es', $request->getLocale());
    }

    public function testTheBackOfficeKeepsTheLanguageOfTheSession(): void
    {
        $request = $this->request(session: 'en', route: null);

        $this->subscriber()->onKernelRequest($this->event($request));

        self::assertSame('en', $request->getLocale());
    }

    /** An address the site does not speak is not a language to switch to. */
    public function testAnUnknownLanguageInTheAddressIsIgnored(): void
    {
        $request = $this->request(session: 'en', route: 'xx');

        $this->subscriber()->onKernelRequest($this->event($request));

        self::assertSame('en', $request->getLocale());
    }

    private function subscriber(): LocaleSubscriber
    {
        $context = $this->createStub(LocaleContextInterface::class);
        $context->method('isSingleLocaleMode')->willReturn(false);
        $context->method('getDefaultLocale')->willReturn('fr');

        return new LocaleSubscriber($context);
    }

    private function request(string $session, ?string $route): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('_locale', $session);

        if (null !== $route) {
            $request->attributes->set('locale', $route);
        }

        return $request;
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
