<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Locale\EventSubscriber;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Locale\EventSubscriber\SingleLocaleRedirectSubscriber;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
final class SingleLocaleRedirectSubscriberTest extends TestCase
{
    /**
     * @param list<string>|null $all
     */
    private function makeContext(bool $single, ?string $default = null, ?array $all = null): LocaleContextInterface
    {
        $context = $this->createMock(LocaleContextInterface::class);
        $context->method('isSingleLocaleMode')->willReturn($single);
        $context->method('getDefaultLocale')->willReturn($default ?? LocaleEnum::default()->value);
        $context->method('getAllLocales')->willReturn($all ?? LocaleEnum::values());

        return $context;
    }

    private function makeEvent(string $uri): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($uri);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testRedirectsNonDefaultLocaleTo301(): void
    {
        $subscriber = new SingleLocaleRedirectSubscriber($this->makeContext(true));
        $event = $this->makeEvent('/en/shop');

        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/fr/shop', $response->getTargetUrl());
    }

    public function testPreservesQueryString(): void
    {
        $subscriber = new SingleLocaleRedirectSubscriber($this->makeContext(true));
        $event = $this->makeEvent('/en/shop?page=2&sort=asc');

        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/fr/shop?page=2&sort=asc', $response->getTargetUrl());
    }

    public function testNoOpWhenLocaleAlreadyMatchesDefault(): void
    {
        $subscriber = new SingleLocaleRedirectSubscriber($this->makeContext(true));
        $event = $this->makeEvent('/fr/shop');

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testNoOpForNonLocalePaths(): void
    {
        $subscriber = new SingleLocaleRedirectSubscriber($this->makeContext(true));
        $event = $this->makeEvent('/suite/configuration/settings');

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testNoOpForUnknownLocaleCode(): void
    {
        $subscriber = new SingleLocaleRedirectSubscriber($this->makeContext(true));
        $event = $this->makeEvent('/zz/shop');

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testNoOpWhenSingleLocaleModeIsOff(): void
    {
        $subscriber = new SingleLocaleRedirectSubscriber($this->makeContext(false));
        $event = $this->makeEvent('/en/shop');

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testHandlesBarePrefix(): void
    {
        $subscriber = new SingleLocaleRedirectSubscriber($this->makeContext(true));
        $event = $this->makeEvent('/en');

        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/fr/', $response->getTargetUrl());
    }
}
