<?php

declare(strict_types=1);

namespace Aurora\Core\Routing;

/**
 * Route requirements that have to account for how Aurora generates URLs.
 *
 * A Vue app is handed a path template rather than a path: Twig asks the
 * generator for the route with `__id__`, `__revisionId__`, `__fieldId__` and
 * the like where the value will go, and the component substitutes it at click
 * time. A requirement of `\d+` rejects that placeholder — and it rejects it
 * while *rendering* the page, so the screen answers 500 before anyone gets
 * near the endpoint the requirement was tightening.
 *
 * ID accepts any placeholder rather than one particular name. Naming them
 * individually is what kept going wrong: `\d+|__id__` was written once, then
 * copied onto a `revisionId` parameter whose template says `__revisionId__`,
 * and the post editor crashed. Three separate occurrences, all of them
 * someone matching a name by hand. The placeholder is always `__<parameter>__`
 * and never looks like an id, so there is nothing here worth being precise
 * about — being precise was the bug.
 */
final class RouteRequirement
{
    /**
     * An entity id, or the `__…__` placeholder a URL template carries in its
     * place.
     */
    public const string ID = '\\d+|__\\w+__';
}
