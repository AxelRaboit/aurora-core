<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentColumnSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentItemSerializerInterface;

/**
 * What a client is shown, which is less than what the studio sees.
 *
 * Built here rather than by reusing the board's builder, and the difference is
 * the point: that one hands out the addresses every write posts to, and a page
 * with no writes has no business carrying them. A read-only screen that
 * receives the URLs of six endpoints is one template mistake away from calling
 * one.
 *
 * The steps travel because a card says which one it is on, and the client
 * reading "à valider" beside a post is most of why they opened the page.
 */
final readonly class PublicSpaceViewBuilder
{
    public function __construct(
        private SpaceContentItemRepository $items,
        private SpaceContentColumnRepository $columns,
        private SpaceContentItemSerializerInterface $itemSerializer,
        private SpaceContentColumnSerializerInterface $columnSerializer,
        private PathTemplateGenerator $pathTemplates,
    ) {}

    /**
     * @param string $token the secret half, which only the request that carried
     *                      it can supply - it is not stored and cannot be read
     *                      back off the link
     *
     * @return array<string, mixed>
     */
    public function view(SpaceAccessLinkInterface $link, string $token): array
    {
        $space = $link->getSpace();

        return [
            'space' => [
                'name' => $space->getName(),
                'description' => $space->getDescription(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
                'timezone' => $space->getTimezone(),
            ],
            'columns' => array_map(
                $this->columnSerializer->serialize(...),
                $this->columns->findForSpace($space),
            ),
            'items' => $this->items($link),
            'expiresAt' => $link->getExpiresAt(),
            'canApprove' => $link->canApprove(),
            // The one address this page may post to, and only when it may.
            // A reader who cannot answer is handed no endpoint at all rather
            // than a button that would be refused.
            'answerPath' => $link->canApprove()
                ? $this->pathTemplates->generate('public_space_answer', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'itemId' => '__id__',
                ])
                : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function items(SpaceAccessLinkInterface $link): array
    {
        return array_map(
            $this->itemSerializer->serialize(...),
            $this->items->findForSpace($link->getSpace()),
        );
    }
}
