<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Security;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who decides what the client sees in their space.
 *
 * **A single rule for the whole space**, set on 06/10/2026 after the Studio
 * audit: a board step, a resource, a deliverable, a chat channel and a file
 * are born hidden from the client, and showing them (or hiding them)
 * requires the `studio.spaces.share` right. The audit had found six, each
 * with its own default and its own right; a reader could not know, without
 * opening the client's page, what a gesture had just published.
 *
 * **The right to share, not the right to edit.** Showing something to the
 * client means sending it to them: the same gesture as giving them an access
 * link, so the same right. A teammate who writes in the space without being
 * able to share it prepares, and someone who can shows.
 *
 * **Three deliberate exceptions**, which this class does not decide because
 * nobody chooses them: a new space shows its review and published steps, the
 * main chat room is always open to the client, and a file the client sent is
 * always visible to them (`AbstractSpaceFile::addedByClient()`, whether it
 * came on a content item or through "Send a file" on their page; the file
 * Manager refuses to hide it).
 *
 * Two uses, and that is why the rule has a name: `PRIVILEGE` in an
 * `#[IsGranted]` for a route that only shows or hides, and
 * {@see canShowOrHide()} for a form that saves everything, visibility
 * included, and only asks for the right if it changes.
 */
final readonly class ClientVisibility
{
    public const string PRIVILEGE = 'studio.spaces.share';

    public function __construct(private Security $security) {}

    public function canShowOrHide(): bool
    {
        return $this->security->isGranted(self::PRIVILEGE);
    }

    /**
     * Is a save that would move the item from `$current` to `$wanted`
     * allowed? Yes if it changes nothing, or if the person has the right.
     */
    public function allowsChange(bool $current, bool $wanted): bool
    {
        return $current === $wanted || $this->canShowOrHide();
    }
}
