<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Access;

/**
 * What the `/uploads/{path}` endpoint is allowed to do with one key.
 *
 * Three answers rather than a boolean, because "may this be served" and "may
 * the application stop looking at it" are different questions. A file that is
 * only readable by some visitors must keep coming through the application:
 * handing out a public hostname or a signed link would take the check off the
 * request path for as long as that link lives.
 */
enum UploadAccessEnum
{
    /**
     * Served to anybody, cacheable by anything in between, and eligible for
     * the redirecting delivery modes. This is what an image embedded in a
     * public page needs, and it is the default for any area no guard claims.
     */
    case Anonymous;

    /**
     * Served, but only through the application and only to this visitor.
     * Never redirected, never stored by a shared cache.
     */
    case Restricted;

    /** Answered with a 404, the same 404 a missing file gets. */
    case Denied;
}
