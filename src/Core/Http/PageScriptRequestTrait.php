<?php

declare(strict_types=1);

namespace Aurora\Core\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a write on a public route comes from this site's own script.
 *
 * A form on any other site can make a visitor's browser post to these routes
 * without asking: an ordinary content type skips the browser's preflight, and
 * `decodeJson()` reads the body whatever its declared type. A poll would count
 * the visitor's vote, a booking would take a slot in their name, a newsletter
 * would sign them up - and each from the visitor's own address, which also
 * walks around the per-address limiters.
 *
 * The custom header closes it at no cost: a form cannot set it, and a `fetch`
 * that sets it triggers the very preflight another origin cannot pass. Every
 * script that writes to these routes already sends it.
 */
trait PageScriptRequestTrait
{
    private function isFromThisPage(Request $request): bool
    {
        return $request->isXmlHttpRequest();
    }
}
