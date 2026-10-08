<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatChannelManagerInterface;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatMessageRepository;
use Aurora\Module\Studio\SpaceChat\Serializer\SpaceChatChannelSerializerInterface;
use Aurora\Module\Studio\SpaceChat\Serializer\SpaceChatMessageSerializerInterface;
use Aurora\Module\Studio\SpaceChat\Service\SpaceChatHub;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What each side of the conversation is handed.
 *
 * Two methods rather than one, and the difference is the same one the space's
 * two view builders already draw: the studio's page gets the addresses it may
 * write to and the client's page gets only the one it may. A read-only link is
 * handed no posting address at all, rather than a box that would be refused.
 *
 * **Both are handed the rooms they may read, and nothing about the others.**
 * Not their names, not their number: a client who could see that three internal
 * rooms exist would know how much is being said out of their sight, which is
 * worse than not knowing rooms exist at all.
 *
 * Both are handed the same `chatStreamUrl`, which is null when no hub is
 * running. That null is the front end's instruction not to open a connection,
 * and it is the only place the optional half of this feature is announced.
 */
final readonly class SpaceChatViewBuilder
{
    public function __construct(
        private SpaceChatMessageRepository $spaceChatMessageRepository,
        private SpaceChatChannelRepository $channelRepository,
        private SpaceChatChannelManagerInterface $channelManager,
        private SpaceChatMessageSerializerInterface $serializer,
        private SpaceChatChannelSerializerInterface $channelSerializer,
        private SpaceChatHub $hub,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplateGenerator,
    ) {}

    /**
     * The studio's side.
     *
     * @return array<string, mixed>
     */
    /**
     * The rooms this person reads, the main one made sure of first.
     *
     * Before the list, and on every load: a space seeded before rooms existed
     * has none, and the conversation must not open on nothing. Public so the
     * page asks once and hands the same list to the view and to the hub's
     * cookie, which both need it.
     *
     * @return list<SpaceChatChannelInterface>
     */
    public function roomsForUser(CustomerSpaceInterface $space, CoreUserInterface $user): array
    {
        $this->channelManager->ensureMain($space);

        return $this->channelRepository->findForUser($space, $user);
    }

    /**
     * The rooms this address reads, as {@see self::roomsForUser()}.
     *
     * @return list<SpaceChatChannelInterface>
     */
    public function roomsForLink(SpaceAccessLinkInterface $link): array
    {
        $this->channelManager->ensureMain($link->getSpace());

        return $this->channelRepository->findForLink($link->getSpace(), $link);
    }

    /**
     * @param list<SpaceChatChannelInterface>|null $rooms     from roomsForUser(), when the caller has them
     * @param int|null                             $channelId the room the address names (`?channel=`, from a
     *                                                        search result); ignored unless it is one of
     *                                                        `$rooms`, so an address cannot open a room
     *                                                        the reader does not have
     */
    public function view(CustomerSpaceInterface $space, CoreUserInterface $user, ?array $rooms = null, ?int $channelId = null): array
    {
        $rooms ??= $this->roomsForUser($space, $user);
        $open = $this->named($rooms, $channelId) ?? $rooms[0] ?? null;

        return [
            'chatChannels' => $this->channels($rooms, $user, null),
            'chatChannelId' => $open?->getId(),
            'chatMessages' => $open instanceof SpaceChatChannelInterface ? $this->messages($open) : [],
            'chatStreamUrl' => $this->hub->subscribeUrl($rooms),
            // Two holes in the same address: the room, and the message to go
            // back from. The route requires both, and a generation missing one
            // throws an exception when the page renders.
            'chatOlderPath' => $this->pathTemplateGenerator->generate('workspace_space_chat_older', [
                'id' => $space->getId(),
                'channelId' => '__channel__',
                'beforeId' => '__before__',
            ]),
            'chatHidePath' => $this->channelTemplate('workspace_space_chat_hide', $space),
            'chatPostPath' => $this->channelTemplate('workspace_space_chat_post', $space),
            'chatReloadPath' => $this->channelTemplate('workspace_space_chat_messages', $space),
            'chatDeletePath' => $this->pathTemplateGenerator->generate('workspace_space_chat_delete', [
                'id' => $space->getId(),
                'channelId' => '__channel__',
                'messageId' => '__id__',
            ]),
            'chatChannelCreatePath' => $this->urlGenerator->generate('workspace_space_chat_channel_create', ['id' => $space->getId()]),
            'chatChannelRenamePath' => $this->channelTemplate('workspace_space_chat_channel_rename', $space),
            'chatChannelAudiencePath' => $this->channelTemplate('workspace_space_chat_channel_audience', $space),
            'chatChannelDeletePath' => $this->channelTemplate('workspace_space_chat_channel_delete', $space),
            'chatChannelInvitePath' => $this->channelTemplate('workspace_space_chat_channel_invite', $space),
            // Two holes again: the room, and the row being removed. It is the
            // member that is named and not the account, because the same person
            // can be in several channels of the same space.
            'chatChannelUninvitePath' => $this->pathTemplateGenerator->generate('workspace_space_chat_channel_uninvite', [
                'id' => $space->getId(),
                'channelId' => '__channel__',
                'memberId' => '__id__',
            ]),
            'chatDirectPath' => $this->urlGenerator->generate('workspace_space_chat_direct', ['id' => $space->getId()]),
            'chatTeam' => $this->team($space),
            // Minus oneself: a private conversation with oneself does not
            // exist, and offering it in the list would be offering an error.
            'chatPeople' => array_values(array_filter(
                $this->team($space),
                static fn (array $person): bool => $person['id'] !== $user->getId(),
            )),
        ];
    }

    /**
     * The client's side.
     *
     * The right to write here is the link's right to comment, deliberately and
     * not a fourth column of its own. A client who may answer their agency on a
     * post is a client who may answer their agency; splitting the two would be
     * a checkbox nobody could explain, on a screen that already asks the studio
     * to make three decisions per link.
     *
     * @param string                               $token the secret half, which only the request that carried
     *                                                    it can supply
     * @param list<SpaceChatChannelInterface>|null $rooms from roomsForLink(), when the caller has them
     *
     * @return array<string, mixed>
     */
    public function publicView(SpaceAccessLinkInterface $link, string $token, ?array $rooms = null): array
    {
        $rooms ??= $this->roomsForLink($link);
        $open = $rooms[0] ?? null;

        return [
            'chatChannels' => $this->channels($rooms, null, $link),
            'chatChannelId' => $open?->getId(),
            'chatMessages' => $open instanceof SpaceChatChannelInterface ? $this->messages($open) : [],
            'chatStreamUrl' => $this->hub->subscribeUrl($rooms),
            // **Neither a private conversation path, nor a directory.** Both
            // left together: one opened a messaging channel to the employees
            // for whoever ticked "peut commenter", the other handed their
            // names and their account ids to any public page. A list of who
            // works for you, by name, has no business in the source of a page
            // whose address gets forwarded.
            'chatDirectPath' => null,
            'chatPeople' => [],
            // The right to write here is `canChat`, separate from the one to
            // comment on a card: they are two conversations.
            'chatPostPath' => $link->canChat()
                ? $this->pathTemplateGenerator->generate('public_space_chat_post', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'channelId' => '__channel__',
                ])
                : null,
            'chatReloadPath' => $this->pathTemplateGenerator->generate('public_space_chat_messages', [
                'selector' => $link->getSelector(),
                'token' => $token,
                'channelId' => '__channel__',
            ]),
            'chatOlderPath' => $this->pathTemplateGenerator->generate('public_space_chat_older', [
                'selector' => $link->getSelector(),
                'token' => $token,
                'channelId' => '__channel__',
                'beforeId' => '__before__',
            ]),
            // Putting a channel away only makes sense for a private
            // conversation, and a link no longer sees any.
            'chatHidePath' => null,
        ];
    }

    /**
     * A reader's rooms, named from their point of view.
     *
     * @param list<SpaceChatChannelInterface> $rooms
     *
     * @return list<array<string, mixed>>
     */
    private function channels(array $rooms, ?CoreUserInterface $user, ?SpaceAccessLinkInterface $link): array
    {
        $this->channelRepository->warmMembers($rooms);

        return array_map(
            fn (SpaceChatChannelInterface $room): array => $this->channelSerializer->serializeFor($room, $user, $link),
            $rooms,
        );
    }

    /** @return list<array<string, mixed>> */
    public function messages(SpaceChatChannelInterface $channel): array
    {
        return array_map(
            $this->serializer->serialize(...),
            $this->spaceChatMessageRepository->findRecentForChannel($channel),
        );
    }

    /**
     * A page of history, older than the given message.
     *
     * `hasMore` says whether anything is left behind it, and it is computed by
     * asking for one row more than what is returned: it is the only way to
     * answer without counting the whole conversation on every scroll up.
     *
     * @return array<string, mixed>
     */
    public function olderPayload(SpaceChatChannelInterface $channel, int $beforeId): array
    {
        $page = $this->spaceChatMessageRepository->findBeforeInChannel($channel, $beforeId, SpaceChatMessageRepository::PAGE + 1);
        $hasMore = count($page) > SpaceChatMessageRepository::PAGE;

        if ($hasMore) {
            // The extra row was there to know, not to be read.
            array_shift($page);
        }

        return [
            'success' => true,
            'chatChannelId' => $channel->getId(),
            'chatOlderMessages' => array_map($this->serializer->serialize(...), $page),
            'chatHasMore' => $hasMore,
        ];
    }

    /**
     * What a write answers with.
     *
     * The window rather than the one row that changed, for the reason the
     * board's writes give: a page that patched its own copy is the first place
     * two readers can disagree. It is also what makes the hub optional - a
     * sender sees their own message from the response, whether or not anything
     * was pushed.
     *
     * @return array<string, mixed>
     */
    public function payload(SpaceChatChannelInterface $channel): array
    {
        return [
            'success' => true,
            'chatChannelId' => $channel->getId(),
            'chatMessages' => $this->messages($channel),
        ];
    }

    /**
     * What a room's list answers with, when the list itself changed.
     *
     * @param list<SpaceChatChannelInterface> $rooms
     *
     * @return array<string, mixed>
     */
    public function channelsPayload(array $rooms, ?CoreUserInterface $user = null, ?SpaceAccessLinkInterface $link = null): array
    {
        return [
            'success' => true,
            'chatChannels' => $this->channels($rooms, $user, $link),
            'chatStreamUrl' => $this->hub->subscribeUrl($rooms),
        ];
    }

    /**
     * Who can be invited into a room: the space's own team.
     *
     * Read off the space rather than from the account list, because a room is
     * the studio's way of dividing work on this customer, and somebody who was
     * never put on the space has no business appearing in its rooms.
     *
     * @return list<array{id: int|null, label: string}>
     */
    private function team(CustomerSpaceInterface $space): array
    {
        $team = [];

        foreach ($space->getMembers() as $member) {
            $user = $member->getUser();
            $team[] = [
                'id' => $user->getId(),
                'label' => $user instanceof User ? $user->getName() : $user->getUserIdentifier(),
            ];
        }

        return $team;
    }

    /**
     * An address with the room left to fill in.
     *
     * The panel switches rooms without asking the server for new addresses, so
     * every path it holds carries `__channel__` where the id goes - the same
     * trick the message paths already use for `__id__`.
     */
    private function channelTemplate(string $route, CustomerSpaceInterface $space): string
    {
        return $this->pathTemplateGenerator->generate($route, [
            'id' => $space->getId(),
            'channelId' => '__channel__',
        ]);
    }

    /**
     * The room the address names, when the reader has it.
     *
     * @param list<SpaceChatChannelInterface> $rooms
     */
    private function named(array $rooms, ?int $channelId): ?SpaceChatChannelInterface
    {
        if (null === $channelId) {
            return null;
        }

        foreach ($rooms as $room) {
            if ($room->getId() === $channelId) {
                return $room;
            }
        }

        return null;
    }
}
