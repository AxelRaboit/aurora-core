<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;

interface SpaceContentAttachmentSerializerInterface
{
    /**
     * For the studio's screens, where the reader has a session.
     *
     * @return array<string, mixed>
     */
    public function serialize(SpaceContentAttachmentInterface $attachment): array;

    /**
     * For the client's page, where the reader has a link and nothing else.
     *
     * Same shape, different addresses: every file is served through the link's
     * own route, so expiry and revocation reach the pictures at the same
     * moment they reach the page.
     *
     * @return array<string, mixed>
     */
    public function serializeForGuest(
        SpaceContentAttachmentInterface $attachment,
        SpaceAccessLinkInterface $link,
        string $token,
    ): array;
}
