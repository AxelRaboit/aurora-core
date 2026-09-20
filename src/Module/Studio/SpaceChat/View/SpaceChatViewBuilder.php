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
            'chatChannels' => $this->channels($rooms, $user, null),
            'chatChannelId' => $open?->getId(),
            'chatMessages' => $open instanceof SpaceChatChannelInterface ? $this->messages($open) : [],
            'chatStreamUrl' => $this->hub->subscribeUrl($rooms),
            // Deux trous dans la même adresse : le salon, et le message d'où
            // l'on repart. La route exige les deux, et une génération à laquelle
            // il en manque un lève une exception au rendu de la page.
            'chatOlderPath' => $this->pathTemplates->generate('workspace_space_chat_older', [
                'id' => $space->getId(),
                'channelId' => '__channel__',
                'beforeId' => '__before__',
            ]),
            'chatHidePath' => $this->channelTemplate('workspace_space_chat_hide', $space),
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
            // Deux trous encore : le salon, et la ligne qu'on retire. C'est le
            // membre qu'on nomme et non le compte, parce qu'une même personne
            // peut être dans plusieurs canaux du même espace.
            'chatChannelUninvitePath' => $this->pathTemplates->generate('workspace_space_chat_channel_uninvite', [
                'id' => $space->getId(),
                'channelId' => '__channel__',
                'memberId' => '__id__',
            ]),
            'chatDirectPath' => $this->urlGenerator->generate('workspace_space_chat_direct', ['id' => $space->getId()]),
            'chatTeam' => $this->team($space),
            // Soi-même en moins : une conversation privée avec soi n'existe pas,
            // et l'offrir dans la liste serait offrir une erreur.
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
            'chatChannels' => $this->channels($rooms, null, $link),
            'chatChannelId' => $open?->getId(),
            'chatMessages' => $open instanceof SpaceChatChannelInterface ? $this->messages($open) : [],
            'chatStreamUrl' => $this->hub->subscribeUrl($rooms),
            // **Ni chemin de conversation privée, ni annuaire.** Les deux
            // sont partis ensemble : l'un ouvrait une messagerie vers les
            // salariés à qui cochait « peut commenter », l'autre livrait
            // leurs noms et les identifiants de leurs comptes à toute page
            // publique. Une liste nominative de qui travaille chez vous n'a
            // rien à faire dans la source d'une page dont l'adresse se
            // transfère.
            'chatDirectPath' => null,
            'chatPeople' => [],
            // Le droit d'écrire ici est `canChat`, distinct de celui de
            // commenter une fiche : ce sont deux conversations.
            'chatPostPath' => $link->canChat()
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
            'chatOlderPath' => $this->pathTemplates->generate('public_space_chat_older', [
                'selector' => $link->getSelector(),
                'token' => $token,
                'channelId' => '__channel__',
                'beforeId' => '__before__',
            ]),
            // Ranger un canal n'a de sens que pour une conversation privée,
            // et un lien n'en voit plus.
            'chatHidePath' => null,
        ];
    }

    /**
     * Les salons d'un lecteur, nommés de son point de vue.
     *
     * @param list<SpaceChatChannelInterface> $rooms
     *
     * @return list<array<string, mixed>>
     */
    private function channels(array $rooms, ?CoreUserInterface $user, ?SpaceAccessLinkInterface $link): array
    {
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
            $this->messages->findRecentForChannel($channel),
        );
    }

    /**
     * Une page d'historique, plus vieille que le message donné.
     *
     * `hasMore` dit s'il reste quelque chose derrière, et il est calculé en
     * demandant une ligne de plus que ce qu'on rend : c'est la seule façon de
     * répondre sans compter toute la conversation à chaque remontée.
     *
     * @return array<string, mixed>
     */
    public function olderPayload(SpaceChatChannelInterface $channel, int $beforeId): array
    {
        $page = $this->messages->findBeforeInChannel($channel, $beforeId, SpaceChatMessageRepository::PAGE + 1);
        $hasMore = count($page) > SpaceChatMessageRepository::PAGE;

        if ($hasMore) {
            // La ligne en trop servait à savoir, pas à être lue.
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
        return $this->pathTemplates->generate($route, [
            'id' => $space->getId(),
            'channelId' => '__channel__',
        ]);
    }
}
