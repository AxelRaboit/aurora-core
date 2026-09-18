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
        private SpaceChatMessageRepository $messages,
        private SpaceChatChannelRepository $channels,
        private SpaceChatChannelManagerInterface $channelManager,
        private SpaceChatMessageSerializerInterface $serializer,
        private SpaceChatChannelSerializerInterface $channelSerializer,
        private SpaceChatHub $hub,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
    ) {}

    /**
     * The studio's side.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space, CoreUserInterface $user): array
    {
        // Before the list, and on every load: a space seeded before rooms
        // existed has none, and the conversation must not open on nothing.
        $this->channelManager->ensureMain($space);

        $rooms = $this->channels->findForUser($space, $user);
        $open = $rooms[0] ?? null;

        return [
            'chatChannels' => array_map($this->channelSerializer->serialize(...), $rooms),
            'chatChannelId' => $open?->getId(),
            'chatMessages' => $open instanceof SpaceChatChannelInterface ? $this->messages($open) : [],
            'chatStreamUrl' => $this->hub->subscribeUrl($rooms),
            'chatPostPath' => $this->channelTemplate('workspace_space_chat_post', $space),
            'chatReloadPath' => $this->channelTemplate('workspace_space_chat_messages', $space),
            'chatDeletePath' => $this->pathTemplates->generate('workspace_space_chat_delete', [
                'id' => $space->getId(),
                'channelId' => '__channel__',
                'messageId' => '__id__',
            ]),
            'chatChannelCreatePath' => $this->urlGenerator->generate('workspace_space_chat_channel_create', ['id' => $space->getId()]),
            'chatChannelRenamePath' => $this->channelTemplate('workspace_space_chat_channel_rename', $space),
            'chatChannelAudiencePath' => $this->channelTemplate('workspace_space_chat_channel_audience', $space),
            'chatChannelDeletePath' => $this->channelTemplate('workspace_space_chat_channel_delete', $space),
            'chatChannelInvitePath' => $this->channelTemplate('workspace_space_chat_channel_invite', $space),
            'chatTeam' => $this->team($space),
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
     * @param string $token the secret half, which only the request that carried
     *                      it can supply
     *
     * @return array<string, mixed>
     */
    public function publicView(SpaceAccessLinkInterface $link, string $token): array
    {
        $space = $link->getSpace();
        $this->channelManager->ensureMain($space);

        $rooms = $this->channels->findForLink($space, $link);
        $open = $rooms[0] ?? null;

        return [
            'chatChannels' => array_map($this->channelSerializer->serialize(...), $rooms),
            'chatChannelId' => $open?->getId(),
            'chatMessages' => $open instanceof SpaceChatChannelInterface ? $this->messages($open) : [],
            'chatStreamUrl' => $this->hub->subscribeUrl($rooms),
            'chatPostPath' => $link->canComment()
                ? $this->pathTemplates->generate('public_space_chat_post', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'channelId' => '__channel__',
                ])
                : null,
            'chatReloadPath' => $this->pathTemplates->generate('public_space_chat_messages', [
                'selector' => $link->getSelector(),
                'token' => $token,
                'channelId' => '__channel__',
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function messages(SpaceChatChannelInterface $channel): array
    {
        return array_map(
            $this->serializer->serialize(...),
            $this->messages->findRecentForChannel($channel),
        );
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
    public function channelsPayload(array $rooms): array
    {
        return [
            'success' => true,
            'chatChannels' => array_map($this->channelSerializer->serialize(...), $rooms),
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
        return $this->pathTemplates->generate($route, [
            'id' => $space->getId(),
            'channelId' => '__channel__',
        ]);
    }
}
