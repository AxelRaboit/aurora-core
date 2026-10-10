<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManager;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const DATE_ATOM;

final readonly class SpaceAccessViewBuilder
{
    public function __construct(
        private SpaceAccessLinkRepository $accessLinkRepository,
        private CustomerSpaceSerializerInterface $spaceSerializer,
        private PathTemplateGenerator $pathTemplateGenerator,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function accessView(CustomerSpaceInterface $space): array
    {
        return [
            'space' => $this->spaceSerializer->serialize($space),
            'links' => $this->links($space),
            'defaultValidDays' => SpaceAccessLinkManager::DEFAULT_VALID_DAYS,
            'maxValidDays' => SpaceAccessLinkManager::MAX_VALID_DAYS,
            'backPath' => $this->urlGenerator->generate('suite_studio_spaces'),
            'boardPath' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            'accessPath' => $this->urlGenerator->generate('workspace_space_access', ['id' => $space->getId()]),
            'issuePath' => $this->urlGenerator->generate('workspace_space_access_issue', ['id' => $space->getId()]),
            'revokePath' => $this->pathTemplateGenerator->generate('workspace_space_access_revoke', ['id' => $space->getId(), 'linkId' => '__id__']),
            'deletePath' => $this->pathTemplateGenerator->generate('workspace_space_access_delete', ['id' => $space->getId(), 'linkId' => '__id__']),
            // The Drive box is only offered if the space has one.
            'driveFolderId' => $space->getDriveFolderId(),
            'previewPath' => $this->pathTemplateGenerator->generate('workspace_space_access_preview', ['id' => $space->getId(), 'linkId' => '__id__']),
            'aliasPath' => $this->pathTemplateGenerator->generate('workspace_space_access_alias', ['id' => $space->getId(), 'linkId' => '__id__']),
            'aliasRemovePath' => $this->pathTemplateGenerator->generate('workspace_space_access_alias_remove', ['id' => $space->getId(), 'linkId' => '__id__']),
            'invitePath' => $this->pathTemplateGenerator->generate('workspace_space_access_invite', ['id' => $space->getId(), 'linkId' => '__id__']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function links(CustomerSpaceInterface $space): array
    {
        $now = new DateTimeImmutable();

        return array_map(
            fn (SpaceAccessLinkInterface $link): array => $this->link($link, $now),
            $this->accessLinkRepository->findForSpace($space),
        );
    }

    /**
     * One link, as the screen needs it.
     *
     * **The address is not in here.** It exists in one response only, the one
     * that minted it, because only that request ever holds the secret: the
     * database keeps a hash, so a list rebuilt tomorrow could not reconstruct
     * the URL even if this wanted to. The screen says so rather than offering a
     * copy button that would have to lie.
     *
     * @return array<string, mixed>
     */
    private function link(SpaceAccessLinkInterface $link, DateTimeImmutable $now): array
    {
        return [
            'id' => $link->getId(),
            'label' => $link->getLabel(),
            'recipientEmail' => $link->getRecipientEmail(),
            'expiresAt' => $link->getExpiresAt()->format(DATE_ATOM),
            'revokedAt' => $link->getRevokedAt()?->format(DATE_ATOM),
            'firstOpenedAt' => $link->getFirstOpenedAt()?->format(DATE_ATOM),
            'lastUsedAt' => $link->getLastUsedAt()?->format(DATE_ATOM),
            'createdAt' => $link->getCreatedAt()->format(DATE_ATOM),
            // Revoked and expired stay apart, because the screen says which and
            // only one of the two is somebody's decision.
            'revoked' => $link->isRevoked(),
            'expired' => $link->isExpired($now),
            'usable' => $link->isUsable($now),
            'canApprove' => $link->canApprove(),
            'canComment' => $link->canComment(),
            'canChat' => $link->canChat(),
            'canUpload' => $link->canUpload(),
            'canSeeDrive' => $link->canSeeDrive(),
            'canSeeContracts' => $link->canSeeContracts(),
            // The short address can be shown again, unlike the long one: its
            // name is kept, encrypted, and its token is computed (10/10/2026).
            'shortUrl' => null === $link->getAlias() ? null : $this->urlGenerator->generate(
                'public_space_alias',
                ['alias' => $link->getAlias()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }

    /**
     * The answer to minting one, which is the only time the address exists.
     *
     * @return array<string, mixed>
     */
    public function issuedPayload(CustomerSpaceInterface $space, SpaceAccessLinkInterface $link): array
    {
        return [
            'success' => true,
            'links' => $this->links($space),
            // Absolute: it goes into a mail somebody sends from their own
            // client, so a path would arrive as a broken address.
            'url' => $this->urlGenerator->generate(
                'public_space_show',
                ['selector' => $link->getSelector(), 'token' => (string) $link->getPlainToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function listPayload(CustomerSpaceInterface $space): array
    {
        return ['success' => true, 'links' => $this->links($space)];
    }
}
