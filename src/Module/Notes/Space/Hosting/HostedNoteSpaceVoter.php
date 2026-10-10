<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Hosting;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Lets the notes engine answer, without the module's privilege, a request a
 * host vouched for.
 *
 * Every controller of the engine is behind `notes.markdown.use`, the right to
 * the Notes module. A client space's team writes the space's notes without
 * that right: the client space is their way in, and its host decides who
 * belongs ({@see NoteSpaceHostInterface::enter()}). Once a host has opened its
 * space for a request, the request is confined to it
 * ({@see NoteSpaceScope}) and this voter grants the privilege for it alone.
 *
 * Grants and never denies: outside a hosted request it abstains, and the
 * module's own voter decides as before. The privilege is not given to the
 * person, only to a request that can reach nothing but the host's space.
 */
final readonly class HostedNoteSpaceVoter implements VoterInterface
{
    /** The Notes module's privilege, the gate of every engine controller. */
    public const string PRIVILEGE = 'notes.markdown.use';

    public function __construct(private NoteSpaceScope $scope) {}

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        if ([self::PRIVILEGE] !== $attributes || !$this->scope->isHosted()) {
            return self::ACCESS_ABSTAIN;
        }

        return self::ACCESS_GRANTED;
    }
}
