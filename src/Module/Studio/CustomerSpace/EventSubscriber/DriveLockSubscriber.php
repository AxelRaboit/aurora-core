<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\EventSubscriber;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;
use function str_starts_with;

/**
 * A locked Drive tab also locks its addresses.
 *
 * **Hiding a tab has never closed a route.** The password would only protect
 * a button if a file download, the archive or the import stayed reachable by
 * typing the address: the protection must be on the side that answers, not
 * the side that draws.
 *
 * Set on the arguments and recognized by route name, for the reason
 * {@see SpaceVisibilitySubscriber} gives: the next Drive route will be
 * covered without anyone thinking about it. That has already happened three
 * times on this module, where each new gesture added its route.
 *
 * **The list is the exception**, deliberately: it answers `locked` so the
 * screen knows to ask for the password. A 404 would make it
 * indistinguishable from a space without a Drive, and the screen would show
 * "aucun dossier" to someone who only has a password to enter.
 */
final readonly class DriveLockSubscriber implements EventSubscriberInterface
{
    /** Every route of a space's Drive carries this prefix. */
    private const string ROUTE_PREFIX = 'workspace_space_drive';

    /** The ones that can say "locked" cleanly, and guard themselves. */
    private const array LISTING_ROUTES = ['workspace_space_drive_list', 'workspace_space_drive_agency_list'];

    public function __construct(
        private DriveLock $lock,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments'];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');

        if (!str_starts_with($route, self::ROUTE_PREFIX) || in_array($route, self::LISTING_ROUTES, true)) {
            return;
        }

        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof CustomerSpaceInterface && $this->lock->isClosedFor($argument)) {
                throw new NotFoundHttpException();
            }
        }
    }
}
