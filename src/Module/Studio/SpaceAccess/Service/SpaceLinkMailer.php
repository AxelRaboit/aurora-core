<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The address of a client space, written to the person it is for.
 *
 * **The link used to leave the application by hand.** The studio created it,
 * copied it, and pasted it into their own mail: the address the link names
 * was a label nothing wrote to, and a link created in a hurry was a link
 * never sent. The application now writes the invitation itself, at creation
 * or later from the link's row.
 *
 * **Which address the mail carries.** At creation the long address exists in
 * readable form, and that is the one sent. Afterwards it does not, and the
 * mail carries the address built from {@see SpaceAccessLinkManagerInterface::mailToken()}:
 * it opens the same page under the same conditions, and sending it does not
 * revoke anything, so a resend never locks out a client who bookmarked the
 * first one.
 *
 * Every mail is written in the customer's language when their sheet names
 * one, the site's email language otherwise.
 */
readonly class SpaceLinkMailer
{
    public function __construct(
        private SpaceAccessLinkManagerInterface $linkManager,
        private MailService $mailService,
        private UrlGeneratorInterface $urlGenerator,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * The absolute address a mail may carry for this link, null when there is
     * none: a link that no longer opens anything, or an application without
     * a secret to sign one.
     */
    public function addressOf(SpaceAccessLinkInterface $link): ?string
    {
        if (!$link->isUsable(new DateTimeImmutable()) || $link->getSpace()->isTrashed()) {
            return null;
        }

        $token = $link->getPlainToken() ?? $this->linkManager->mailToken($link);
        if (null === $token) {
            return null;
        }

        return $this->urlGenerator->generate(
            'public_space_show',
            ['selector' => $link->getSelector(), 'token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /** The language to write to the space's customer in, null for the site's. */
    public function localeOf(CustomerSpaceInterface $space): ?string
    {
        return $space->getCustomer()->getLocale();
    }

    /** @return bool whether the invitation went out */
    public function invite(SpaceAccessLinkInterface $link): bool
    {
        $url = $this->addressOf($link);
        if (null === $url) {
            return false;
        }

        $space = $link->getSpace();

        $this->mailService->send(
            to: $link->getRecipientEmail(),
            subjectKey: 'studio.email.space_invitation.subject',
            template: '@Studio/email/space_invitation.html.twig',
            context: [
                'space' => $space,
                'link' => $link,
                'url' => $url,
                'expiresAt' => $link->getExpiresAt(),
            ],
            locale: $this->localeOf($space),
            subjectParameters: ['{space}' => $space->getName()],
        );

        $this->auditLogger->log('studio', 'space_access_link.invitation_sent', 'SpaceAccessLink', $link->getId(), [
            'space' => $space->getName(),
            'recipient' => $link->getRecipientEmail(),
        ]);

        return true;
    }
}
