<?php

declare(strict_types=1);

namespace Aurora\Core\Routing;

/**
 * Route requirements that have to account for how Aurora generates URLs.
 *
 * A Vue app is handed a path template rather than a path: Twig asks the
 * generator for the route with `__id__` where the id will go, and the
 * component substitutes it at click time. A requirement of `\d+` rejects
 * that placeholder — and it rejects it while rendering the page, so the
 * screen answers 500 before anyone gets near the endpoint the requirement
 * was tightening.
 *
 * This has now been introduced twice, in two domains, by tightening a
 * requirement that looked too permissive. It lives here so the next person
 * to reach for `\d+` finds the reason attached to the alternative rather
 * than in a comment on some other controller.
 */
final class RouteRequirement
{
    /** An entity id, or the placeholder a URL template carries in its place. */
    public const string ID = '\\d+|__id__';

    /**
     * The same, for a route whose parameter is not called `id`.
     *
     * @param string $parameter the route parameter name, e.g. `commentId`
     */
    public static function idOr(string $parameter): string
    {
        return sprintf('\\d+|__%s__', $parameter);
    }
}
