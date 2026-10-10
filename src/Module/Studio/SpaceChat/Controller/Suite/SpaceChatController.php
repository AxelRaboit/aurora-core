<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Security\ClientVisibility;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannel;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMember;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatChannelManagerInterface;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatMessageManagerInterface;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Module\Studio\SpaceChat\Service\SpaceChatReadTracker;
use Aurora\Module\Studio\SpaceChat\View\SpaceChatViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The studio's side of a space's conversation.
 *
 * Alongside the board's controller rather than inside it, on the same
 * `/workspace/{id}` root: it is the same screen to a reader, and a different
 * subject to the code. The board's controller is about cards, columns and the
 * files on them; none of its routes would read any better for having a chat
 * bolted to them.
 *
 * **Every route names the space and every handler checks what it was handed
 * belongs to it.** The message and the room both arrive as their own entities
 * through the URL, so nothing stops a crafted request from naming one client's
 * room under another client's space - `assertOwned` is what does.
 *
 * Reading is `view` and writing is `edit`, which is how the rest of the module
 * splits it: somebody who may look at a space can follow the conversation, and
 * writing into a customer's space is an act of the agency. Opening and closing
 * rooms is `edit` as well: it arranges the customer's space.
 */
#[Route('/workspace/{id}/chat', name: 'workspace_space_chat', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceChatController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use SpaceOwnershipTrait;

    public function __construct(
        protected readonly SpaceChatMessageManagerInterface $messages,
        protected readonly SpaceChatChannelManagerInterface $channels,
        protected readonly SpaceChatChannelRepository $channelRepository,
        protected readonly SpaceChatViewBuilder $viewBuilder,
        protected readonly UserRepository $userRepository,
        protected readonly Security $security,
        protected readonly ClientVisibility $clientVisibility,
        protected readonly ?SpaceChatReadTracker $readTracker = null,
    ) {}

    /**
     * One room's conversation as it stands.
     *
     * **This is what makes the hub optional.** A page with no live connection -
     * because no hub is configured, or because the browser dropped the one it
     * had - asks here instead, and is right again. Without it, "no hub" would
     * mean "reload the page to see an answer", which is not a chat.
     */
    #[Route('/{channelId}/messages', name: '_messages', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function messages(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        return $this->jsonSuccess($this->viewBuilder->payload($channel));
    }

    /**
     * What comes before what the page already holds.
     *
     * The marker is the oldest message shown, not a page number: a
     * conversation where someone writes while the reader scrolls up would shift
     * everything, and the reader would see the same line twice.
     */
    #[Route('/{channelId}/older/{beforeId}', name: '_older', requirements: ['channelId' => '\d+', 'beforeId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function older(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        int $beforeId,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        return $this->jsonSuccess($this->viewBuilder->olderPayload($channel, $beforeId));
    }

    /** Removes a private conversation from one's own list, without erasing anything. */
    #[Route('/{channelId}/hide', name: '_hide', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function hide(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof CoreUserInterface) {
            return $this->jsonInvalidInput(['channel' => 'suite.studio.space_chat.errors.needs_account']);
        }

        try {
            $this->channels->hideDirect($channel, $currentUser, null);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    #[Route('/{channelId}', name: '_post', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function post(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        $body = Str::trimFromArray($this->decodeJson($request), 'body');

        if ('' === $body) {
            return $this->jsonInvalidInput(['body' => 'suite.studio.space_chat.errors.body_required']);
        }

        try {
            $this->messages->postAsStudio($channel, $body);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        // What somebody answers, they have read.
        $this->markRead($channel);

        return $this->jsonSuccess($this->viewBuilder->payload($channel));
    }

    /**
     * The room is on screen: everything in it is read, up to now.
     *
     * Asked by the panel rather than done when the space opens: a space opened
     * on its calendar has read nothing of its conversation.
     */
    #[Route('/{channelId}/read', name: '_read', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function read(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        $this->markRead($channel);

        return $this->jsonSuccess([]);
    }

    private function markRead(SpaceChatChannel $channel): void
    {
        $user = $this->security->getUser();

        if ($user instanceof CoreUserInterface) {
            $this->readTracker?->markRead($channel, $user);
        }
    }

    /**
     * Removes one of the studio's own messages.
     *
     * A client's message is refused by the Manager, for the reason the card
     * threads give: a provider who can delete a customer's complaint has a
     * record of the engagement that proves nothing.
     */
    #[Route('/{channelId}/{messageId}/delete', name: '_delete', requirements: ['channelId' => '\d+', 'messageId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function delete(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        #[MapEntity(id: 'messageId')]
        SpaceChatMessage $message,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);
        $this->assertOwned($space, $message->getSpace()->getId());

        // A message from another room, named under this one: belonging to the
        // address's room would say nothing about the message's room.
        if ($message->getChannel()->getId() !== $channel->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $this->messages->delete($message);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($channel));
    }

    #[Route('/channels/create', name: '_channel_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function createChannel(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $name = Str::trimFromArray($payload, 'name');
        $openToClient = true === ($payload['openToClient'] ?? false);

        // A channel is born internal; creating it shown to the client is
        // showing it to them, and that requires the right to share the space.
        if (!$this->clientVisibility->allowsChange(false, $openToClient)) {
            return $this->jsonForbidden();
        }

        try {
            $channel = $this->channels->create($space, $name, $openToClient);

            // Whoever opened the room is in it. Without this the room would
            // vanish from its author's own list, which reads as a room that
            // was not created.
            $author = $this->security->getUser();

            if ($author instanceof CoreUserInterface) {
                $this->channels->invite($channel, $author);
            }
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    #[Route('/channels/{channelId}/rename', name: '_channel_rename', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function renameChannel(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        try {
            $this->channels->rename($channel, Str::trimFromArray($this->decodeJson($request), 'name'));
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    /**
     * Whether the client reads this room: shown to the client or hidden from
     * them, under the right to share the space like everything else a space
     * can show.
     */
    #[Route('/channels/{channelId}/audience', name: '_channel_audience', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    #[IsGranted(ClientVisibility::PRIVILEGE)]
    public function channelAudience(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        try {
            $this->channels->setOpenToClient($channel, true === ($this->decodeJson($request)['openToClient'] ?? false));
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    #[Route('/channels/{channelId}/delete', name: '_channel_delete', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function deleteChannel(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        try {
            $this->channels->delete($channel);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    /**
     * Adds somebody to a room.
     *
     * Only an account, and only one already on the space's team: a room is the
     * studio's way of dividing its own work, and inviting a stranger into a
     * customer's space is a different decision, taken on the space itself.
     */
    #[Route('/channels/{channelId}/invite', name: '_channel_invite', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function inviteToChannel(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        $userId = (int) ($this->decodeJson($request)['userId'] ?? 0);
        $user = $userId > 0 ? $this->userRepository->find($userId) : null;

        if (!$user instanceof CoreUserInterface || !$this->isOnTheTeam($space, $user)) {
            return $this->jsonInvalidInput(['userId' => 'suite.studio.space_chat.errors.not_on_the_team']);
        }

        try {
            $this->channels->invite($channel, $user);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    /**
     * Removes someone from a channel.
     *
     * The counterpart of inviting, which was missing: a team changes, and a
     * channel whose list can only grow ends up no longer being one.
     *
     * **Nothing is erased.** What the person wrote stays in the channel, with
     * their name: a message is a dated fact, not a property one takes away when
     * leaving. They simply stop seeing it and writing in it, and inviting them
     * again puts them back where they were.
     *
     * The member comes by its id and not by its account, because it is the row
     * being removed and not the person: they can be in other channels of the
     * same space, and stay there.
     */
    #[Route('/channels/{channelId}/members/{memberId}/remove', name: '_channel_uninvite', requirements: ['channelId' => '\\d+', 'memberId' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function removeFromChannel(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        #[MapEntity(id: 'memberId')]
        SpaceChatChannelMember $member,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());
        $this->assertInRoom($channel);

        // The member of another room named under this one: two entities the
        // URL brings separately, so two checks.
        if ($member->getChannel()->getId() !== $channel->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $this->channels->removeMember($member);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    /**
     * Opens the private conversation with somebody, or reopens it.
     *
     * The second press lands in the first conversation: two people have one
     * between them, and the manager looks the pair up before writing anything.
     */
    #[Route('/direct', name: '_direct', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function openDirect(CustomerSpace $space, Request $request): JsonResponse
    {
        $currentUser = $this->security->getUser();
        $userId = (int) ($this->decodeJson($request)['userId'] ?? 0);
        $other = $userId > 0 ? $this->userRepository->find($userId) : null;

        if (!$currentUser instanceof CoreUserInterface) {
            return $this->jsonInvalidInput(['participant' => 'suite.studio.space_chat.errors.needs_account']);
        }

        if (!$other instanceof CoreUserInterface || !$this->isOnTheTeam($space, $other)) {
            return $this->jsonInvalidInput(['userId' => 'suite.studio.space_chat.errors.not_on_the_team']);
        }

        try {
            $channel = $this->channels->openDirect($space, $currentUser, null, $other, null);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            ...$this->channelsPayload($space),
            'chatChannelId' => $channel->getId(),
        ]);
    }

    /** @return array<string, mixed> */
    private function channelsPayload(CustomerSpace $space): array
    {
        $user = $this->security->getUser();

        return $this->viewBuilder->channelsPayload(
            $user instanceof CoreUserInterface
                ? $this->channelRepository->findForUser($space, $user)
                : $this->channelRepository->findForSpace($space),
            $user instanceof CoreUserInterface ? $user : null,
        );
    }

    /**
     * Only a room the reader is in: the main one, which everybody on the
     * space shares, or one they were invited into.
     *
     * The list already said so and the routes did not: any member of the
     * space who knew a room's number could read a private conversation, post
     * in it, or invite themselves in. A 404, like a room of another space, so
     * a number does not tell whether the room exists.
     */
    private function assertInRoom(SpaceChatChannel $channel): void
    {
        if (SpaceChatChannelKindEnum::Main === $channel->getKind()) {
            return;
        }

        $currentUser = $this->security->getUser();

        if ($currentUser instanceof CoreUserInterface) {
            foreach ($channel->getMembers() as $member) {
                if ($member->getUser()?->getId() === $currentUser->getId()) {
                    return;
                }
            }
        }

        throw $this->createNotFoundException();
    }

    private function isOnTheTeam(CustomerSpace $space, CoreUserInterface $user): bool
    {
        foreach ($space->getMembers() as $member) {
            if ($member->getUser()->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }
}
