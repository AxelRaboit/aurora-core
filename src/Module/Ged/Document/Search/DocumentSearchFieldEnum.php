<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Search;

/**
 * Where the search box looks.
 *
 * `All` is the resting state and reads every field below at once: a library
 * is searched by whatever someone remembers of a document, which is as often
 * the name of the file it came in as its title. The narrower cases are for
 * the word that shows up everywhere and has to be pinned to one place.
 */
enum DocumentSearchFieldEnum: string
{
    case All = 'all';
    /** The title, the reference, and an alternate's own title and label. */
    case Title = 'title';
    /** The name the file was uploaded under, and the one it is stored as. */
    case File = 'file';
    /** Description, alt text, caption and credit. */
    case Text = 'text';
    /** Tag, category and folder names. */
    case Classification = 'classification';
}
