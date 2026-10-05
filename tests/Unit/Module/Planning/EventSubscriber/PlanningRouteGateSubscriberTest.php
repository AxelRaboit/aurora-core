<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning\EventSubscriber;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Planning\EventSubscriber\PlanningRouteGateSubscriber;
use Aurora\Module\Planning\PlanningContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function sprintf;

/**
 * The calendar switched off stops answering, feeds and shared pages included.
 */
final class PlanningRouteGateSubscriberTest extends TestCase
{
    public function testTheCalendarOffClosesItsScreensAndWhatItPublishes(): void
    {
        foreach (['suite_planning_calendar', 'suite_planning_events_create', 'planning_feed_show', 'planning_share_show', 'planning_share_events'] as $route) {
            self::assertTrue($this->blocks($route, enabled: false), sprintf('route %s', $route));
            self::assertFalse($this->blocks($route, enabled: true), sprintf('route %s', $route));
        }
    }

    public function testRoutesOfAnotherModuleAreNotItsBusiness(): void
    {
        self::assertFalse($this->blocks('suite_studio_spaces', enabled: false));
    }

    private function blocks(string $route, bool $enabled): bool
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturn($enabled);

        $request = new Request();
        $request->attributes->set('_route', $route);

        try {
            new PlanningRouteGateSubscriber(new PlanningContext($checker))->onKernelRequest(
                new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
            );
        } catch (NotFoundHttpException) {
            return true;
        }

        return false;
    }
}
