<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Hosting;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that keeps a space of notes inside one of its own screens.
 *
 * **One notes engine, notes that live where they belong.** A client space's
 * notes are written from the client space, by its team: they have nothing to
 * do in the Notes module, next to somebody's personal notebook. The engine -
 * storage, editor, history, links, search, trash - stays the Notes module's;
 * the host decides who may work in its spaces and where they are shown
 * (decision of 10/10/2026, replacing the "door onto the Notes module" of
 * 06/10/2026).
 *
 * A hosted space carries the host's key in `NoteSpace::$managedBy`. Notes
 * never learns what the host is: it asks the host, through this contract, and
 * the host answers with its own rules - a client space's team, for Studio.
 *
 * Implementations are found by their tag; one per key.
 */
#[AutoconfigureTag(self::TAG)]
interface NoteSpaceHostInterface
{
    public const string TAG = 'aurora.notes.space_host';

    /** The marker this host's spaces carry in `NoteSpace::$managedBy`. */
    public function getKey(): string;

    /**
     * The note space behind one of the host's references, when this person
     * may work in it; null otherwise, without saying why.
     *
     * The reference is the host's own identifier (a client space's id), the
     * one {@see self::referenceOf()} gives back.
     */
    public function enter(string $reference, CoreUserInterface $user): ?NoteSpaceInterface;

    /** The host's reference for one of its spaces, null when it no longer knows it. */
    public function referenceOf(NoteSpaceInterface $space): ?string;

    /** The name people know the space by in the host: a client space's name. */
    public function labelOf(NoteSpaceInterface $space): ?string;

    /** Where the host shows the space and its notes, null when it no longer shows it. */
    public function pagePaths(NoteSpaceInterface $space): ?NoteSpacePagePaths;
}
