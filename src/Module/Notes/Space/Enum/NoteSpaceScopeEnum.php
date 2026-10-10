<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Enum;

use Aurora\Module\Notes\Space\Hosting\NoteSpaceScope;

/**
 * Which note spaces a request works with.
 *
 * The notes engine serves two kinds of screens: the Notes module itself, and
 * a module that hosts a space of notes in one of its own screens - a client
 * space's notes, written from the client space. Both read the same tables
 * through the same routes; the scope is what keeps them apart (visual
 * redesign follow-up, 10/10/2026).
 *
 * The values are read in DQL, see {@see NoteSpaceScope::clause()}:
 * add cases, never rename one.
 */
enum NoteSpaceScopeEnum: string
{
    /** Every space the person may read: commands, the general trash, the search palette. */
    case All = 'all';

    /** The Notes module: its own spaces, without those another module hosts. */
    case Module = 'module';

    /** The hosted spaces only, whichever host: what an image route serves to a person without the module. */
    case Hosts = 'hosts';

    /** One hosted space, the one its host opened: nothing else exists for the request. */
    case Hosted = 'hosted';
}
