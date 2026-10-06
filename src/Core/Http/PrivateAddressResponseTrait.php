<?php

declare(strict_types=1);

namespace Aurora\Core\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * The headers a page reached by a secret address sends on every answer.
 *
 * Shared by every public page whose address is the whole secret: a deliverable's
 * link, a customer space's, a contract's, a publication's reading link. They
 * used to carry three identical copies of this, and a fourth page is how two
 * of them would come to differ in how thoroughly they keep the secret.
 *
 * `no-referrer` so following a link off the page does not hand its address to
 * another site. `noindex` so a crawler that found the address does not
 * publish it. `no-store` so a shared machine's back button and a proxy cache
 * do not keep a copy for the next visitor.
 */
trait PrivateAddressResponseTrait
{
    private function privately(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }
}
