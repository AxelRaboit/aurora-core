<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannel;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatChannelManagerInterface;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatMessageManagerInterface;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
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
        protected readonly UserRepository $users,
        protected readonly Security $security,
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

        return $this->jsonSuccess($this->viewBuilder->payload($channel));
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

        $body = Str::trimFromArray($this->decodeJson($request), 'body');

        if ('' === $body) {
            return $this->jsonInvalidInput(['body' => 'backend.studio.space_chat.errors.body_required']);
        }

        try {
            $this->messages->postAsStudio($channel, $body);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($channel));
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
        $this->assertOwned($space, $message->getSpace()->getId());

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

        try {
            $channel = $this->channels->create($space, $name, (bool) ($payload['openToClient'] ?? false));

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

        try {
            $this->channels->rename($channel, Str::trimFromArray($this->decodeJson($request), 'name'));
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->channelsPayload($space));
    }

    /** Whether the client reads this room. The one setting that lets something out of the studio. */
    #[Route('/channels/{channelId}/audience', name: '_channel_audience', requirements: ['channelId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function channelAudience(
        CustomerSpace $space,
        #[MapEntity(id: 'channelId')]
        SpaceChatChannel $channel,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $channel->getSpace()->getId());

        try {
            $this->channels->setOpenToClient($channel, (bool) ($this->decodeJson($request)['openToClient'] ?? false));
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

        $userId = (int) ($this->decodeJson($request)['userId'] ?? 0);
        $user = $userId > 0 ? $this->users->find($userId) : null;

        if (!$user instanceof CoreUserInterface || !$this->isOnTheTeam($space, $user)) {
            return $this->jsonInvalidInput(['userId' => 'backend.studio.space_chat.errors.not_on_the_team']);
        }

        try {
            $this->channels->invite($channel, $user);
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
        $me = $this->security->getUser();
        $userId = (int) ($this->decodeJson($request)['userId'] ?? 0);
        $other = $userId > 0 ? $this->users->find($userId) : null;

        if (!$me instanceof CoreUserInterface) {
            return $this->jsonInvalidInput(['participant' => 'backend.studio.space_chat.errors.needs_account']);
        }

        if (!$other instanceof CoreUserInterface || !$this->isOnTheTeam($space, $other)) {
            return $this->jsonInvalidInput(['userId' => 'backend.studio.space_chat.errors.not_on_the_team']);
        }

        try {
            $channel = $this->channels->openDirect($space, $me, null, $other, null);
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
