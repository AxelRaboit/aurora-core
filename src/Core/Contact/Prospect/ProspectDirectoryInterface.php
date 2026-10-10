<?php

declare(strict_types=1);

namespace Aurora\Core\Contact\Prospect;

/**
 * Turns a website contact into a prospect, for a module that receives
 * contacts without owning customers.
 *
 * Lives in core so the producer (Editorial's forms) and the consumer
 * (Studio's customers) never import each other. Each side checks its own
 * switch: the forms offer the gesture only while `isAvailable()` says so.
 */
interface ProspectDirectoryInterface
{
    /** Whether prospects can be created at all, by the person reading. */
    public function isAvailable(): bool;

    /**
     * The prospects already created from these references, as the address of
     * their page.
     *
     * @param list<string> $sourceReferences
     *
     * @return array<string, string> reference => path
     */
    public function pathsForSources(array $sourceReferences): array;

    /**
     * Creates the prospect, or finds the one already created from the same
     * reference, and returns the path of its page.
     */
    public function createFromWebsiteContact(WebsiteContact $contact): string;
}
