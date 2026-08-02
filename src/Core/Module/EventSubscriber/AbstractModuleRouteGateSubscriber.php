<?php

declare(strict_types=1);

namespace Aurora\Core\Module\EventSubscriber;

use Aurora\Core\Frontend\Contract\FrontendInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 404s a module's routes while the toggle behind them is off.
 *
 * Switching a module off removes its side-menu entries, and that is all it
 * removes — the controllers stay registered and answer 200 to anyone who
 * kept the URL or guessed it. A disabled module has to be disabled at the
 * door, not merely hidden from the menu.
 *
 * The public front has its own version of this driven by
 * {@see FrontendInterface::getRoutePrefixes()};
 * this is the backend half, and it works at sub-module granularity because
 * that is where the toggles are — turning off "Taxonomies" alone must close
 * the taxonomy screens and leave the rest of Editorial open.
 *
 * Matching is on the route name rather than the path: paths can be
 * reorganised, and a route that stops being covered by its gate because
 * someone moved a URL is a silent hole.
 */
abstract readonly class AbstractModuleRouteGateSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route', '');

        // Every request passes through here, most of them for routes this
        // module knows nothing about. A handful of string comparisons settle
        // those before any toggle is resolved.
        if (!$this->isOurs($route)) {
            return;
        }

        foreach ($this->gates() as $prefix => $enabled) {
            if (str_starts_with($route, $prefix) && !$enabled) {
                throw new NotFoundHttpException();
            }
        }
    }

    /**
     * The route-name prefixes every gate below sits under, e.g.
     * `backend_editorial_`. Requests outside them are none of this module's
     * business and are waved through without touching the settings.
     *
     * @return list<string>
     */
    abstract protected function routeNamespaces(): array;

    /**
     * Route-name prefix → whether the module behind it is on.
     *
     * Order them broadest first: the top-level toggle should close
     * everything under it without each sub-gate having to repeat the check.
     *
     * @return array<string, bool>
     */
    abstract protected function gates(): array;

    private function isOurs(string $route): bool
    {
        return array_any($this->routeNamespaces(), fn ($namespace): bool => str_starts_with($route, (string) $namespace));
    }
}
