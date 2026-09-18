<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Enum;

/**
 * What a conversation is, which decides who may open it and how it is listed.
 *
 * **Three kinds and one table, which is what the applications people already
 * use do.** A private conversation is a conversation with two people in it;
 * giving it its own table would mean a second message row, a second serializer,
 * a second live channel and a second panel, all to express "the member list has
 * two entries". The interface still shows two lists, because that is how people
 * think about them - channels are rooms you walk into, a private conversation
 * is somebody you are talking to - and that separation belongs in the screen
 * rather than in the schema.
 */
enum SpaceChatChannelKindEnum: string
{
    /**
     * The one every space is born with, and the one the client lands in.
     *
     * It cannot be deleted or closed to the client: it is the conversation the
     * space had before channels existed, and a space with no way to reach the
     * studio would be a space that lost its point.
     */
    case Main = 'main';

    /** A room opened by the studio, with the people it invited into it. */
    case Topic = 'topic';

    /**
     * Two people, and nobody else, ever.
     *
     * Opened rather than created: there is at most one between any two
     * participants, and asking for it twice reopens the first.
     */
    case Direct = 'direct';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
