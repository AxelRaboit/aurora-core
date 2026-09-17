<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatMessageRepository;
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
 * Both are handed the same `chatStreamUrl`, which is null when no hub is
 * running. That null is the front end's instruction not to open a connection,
 * and it is the only place the optional half of this feature is announced.
 */
final readonly class SpaceChatViewBuilder
{
    public function __construct(
        private SpaceChatMessageRepository $messages,
        private SpaceChatMessageSerializerInterface $serializer,
        private SpaceChatHub $hub,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
    ) {}

    /**
     * The studio's side.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            'chatMessages' => $this->messages($space),
            'chatStreamUrl' => $this->hub->subscribeUrl($space),
            'chatPostPath' => $this->urlGenerator->generate('workspace_space_chat_post', ['id' => $space->getId()]),
            'chatReloadPath' => $this->urlGenerator->generate('workspace_space_chat_messages', ['id' => $space->getId()]),
            'chatDeletePath' => $this->pathTemplates->generate('workspace_space_chat_delete', [
                'id' => $space->getId(),
                'messageId' => '__id__',
            ]),
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

        return [
            'chatMessages' => $this->messages($space),
            'chatStreamUrl' => $this->hub->subscribeUrl($space),
            'chatPostPath' => $link->canComment()
                ? $this->urlGenerator->generate('public_space_chat_post', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ])
                : null,
            'chatReloadPath' => $this->urlGenerator->generate('public_space_chat_messages', [
                'selector' => $link->getSelector(),
                'token' => $token,
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function messages(CustomerSpaceInterface $space): array
    {
        return array_map(
            $this->serializer->serialize(...),
            $this->messages->findRecentForSpace($space),
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
    public function payload(CustomerSpaceInterface $space): array
    {
        return [
            'success' => true,
            'chatMessages' => $this->messages($space),
        ];
    }
}
