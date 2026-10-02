<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Search;

/**
 * The shape of a picture, for finding a visual that fits a slot.
 *
 * Decided on the stored width and height, so a document without dimensions
 * (a PDF, an archive, an image not yet measured) has no orientation and
 * matches none of the three.
 */
enum DocumentOrientationEnum: string
{
    case Landscape = 'landscape';
    case Portrait = 'portrait';
    case Square = 'square';
}
