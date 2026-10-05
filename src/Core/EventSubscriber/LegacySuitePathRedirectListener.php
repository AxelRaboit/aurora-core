<?php

declare(strict_types=1);

namespace Aurora\Core\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * The suite used to live under `/backend`. Links to it are still out there:
 * invitations, password resets and e-mail checks already sent, bookmarks, tabs
 * left open. Each one is sent to the same page under `/suite`, query string
 * kept.
 *
 * 308 rather than 301: a form posted from a tab opened before the move stays a
 * POST, with its body, instead of turning into a GET that would lose it.
 * Runs before the router (32) and the firewall (8), so the old address never
 * reaches either.
 */
#[AsEventListener(priority: 40)]
final readonly class LegacySuitePathRedirectListener
{
    private const string LEGACY_PREFIX = '/backend';

    private const string PREFIX = '/suite';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (self::LEGACY_PREFIX !== $path && !str_starts_with($path, self::LEGACY_PREFIX.'/')) {
            return;
        }

        $target = $request->getBaseUrl().self::PREFIX.mb_substr($path, mb_strlen(self::LEGACY_PREFIX));
        $query = $request->getQueryString();

        $event->setResponse(new RedirectResponse(
            null === $query ? $target : $target.'?'.$query,
            Response::HTTP_PERMANENTLY_REDIRECT,
        ));
    }
}
