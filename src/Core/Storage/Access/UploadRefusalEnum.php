<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Access;

/**
 * Why an upload was refused, without saying it in any particular language.
 *
 * A reason rather than a message, because the same rule is applied on surfaces
 * that do not speak to the same person: the back office tells a colleague what
 * the library accepts, and a client's page tells a customer what to send
 * instead. Each maps these cases to its own keys.
 *
 * Three cases and not one, because they send somebody to three different
 * fixes. "The upload failed" covering all of them is what makes a reader retry
 * the identical file forever.
 */
enum UploadRefusalEnum
{
    /** Over the ceiling - the application's own, or PHP's, which bites first. */
    case TooLarge;

    /** A type this surface does not accept. */
    case TypeRefused;

    /** Never arrived intact. A truncated transfer, a disk that was full. */
    case Broken;
}
