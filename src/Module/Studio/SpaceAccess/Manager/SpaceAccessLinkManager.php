<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\AbstractSpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function hash_equals;
use function sprintf;

#[AsAlias(SpaceAccessLinkManagerInterface::class)]
class SpaceAccessLinkManager implements SpaceAccessLinkManagerInterface
{
    /**
     * How long an address lives when nobody says.
     *
     * Ninety days rather than a year: an engagement is reviewed about that
     * often, and a link that outlives the work is the one nobody remembers to
     * close. Renewing is one click on a screen somebody is already looking at.
     */
    public const int DEFAULT_VALID_DAYS = 90;

    /** A year, past which nobody is choosing a duration, they are avoiding one. */
    public const int MAX_VALID_DAYS = 365;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly SpaceAccessLinkRepository $links,
    ) {}

    /** How long a preview lasts. Enough to look, too short to forget. */
    protected const int PREVIEW_MINUTES = 15;

    public function issue(
        CustomerSpaceInterface $space,
        string $recipientEmail,
        ?string $label,
        int $validForDays,
        bool $canApprove,
        bool $canComment,
        bool $canChat = true,
        bool $canUpload = false,
        bool $canSeeDrive = true,
    ): SpaceAccessLinkInterface {
        $days = max(1, min(static::MAX_VALID_DAYS, $validForDays));

        $link = $this->createLink();
        $link->mint();
        $link
            ->setSpace($space)
            ->setRecipientEmail($recipientEmail)
            ->setLabel($label)
            ->setCanApprove($canApprove)
            ->setCanComment($canComment)
            ->setCanChat($canChat)
            ->setCanUpload($canUpload)
            ->setCanSeeDrive($canSeeDrive)
            ->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', $days)));

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $this->auditIssued($link);

        return $link;
    }

    /**
     * A preview of this link, open for a few minutes.
     *
     * **A real link, because a fake one would show nothing.** The clear-text
     * token only exists at creation; a page built with an invented token
     * displays and answers nothing, so neither the Drive folder nor the files
     * appear in it - that is, precisely what one came to check.
     *
     * It copies the rights so that the screen is the same, **and writes
     * nothing**: that refusal does not come from the rights but from what it
     * is, and lives in the public controller. Without this rule, a careless
     * click on "Validé" would record an answer in the client's name.
     *
     * Only one at a time per link: the previous one is deleted, which keeps a
     * still valid address from lingering after the rights it was meant to show
     * have changed.
     */
    public function preview(SpaceAccessLinkInterface $source): SpaceAccessLinkInterface
    {
        $existing = $this->links->findPreviewOf($source);

        if ($existing instanceof SpaceAccessLinkInterface) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        $link = $this->createLink();
        $link->mint();
        $link
            ->setSpace($source->getSpace())
            ->setRecipientEmail($source->getRecipientEmail())
            ->setLabel($source->getLabel())
            ->setCanApprove($source->canApprove())
            ->setCanComment($source->canComment())
            ->setCanChat($source->canChat())
            ->setCanUpload($source->canUpload())
            ->setCanSeeDrive($source->canSeeDrive())
            ->setPreviewOf($source)
            // A few minutes: time to look, not time to forget.
            ->setExpiresAt(new DateTimeImmutable(sprintf('+%d minutes', static::PREVIEW_MINUTES)));

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        // No "link issued" audit: nobody received an address, and one line
        // per glance would drown the ones that matter.
        return $link;
    }

    public function revoke(SpaceAccessLinkInterface $link): void
    {
        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditRevoked($link);
    }

    /**
     * Deleting a link rather than revoking it.
     *
     * Both exist because they say different things. Revoking closes an address
     * and keeps the record that it was opened, by whom and when - which is what
     * somebody asks a month later. Deleting is for the one issued to the wrong
     * mailbox thirty seconds ago, where the record is noise.
     */
    public function delete(SpaceAccessLinkInterface $link): void
    {
        $this->auditDeleted($link);

        $this->entityManager->remove($link);
        $this->entityManager->flush();
    }

    public function resolveUsable(string $selector, string $token): ?SpaceAccessLinkInterface
    {
        $link = $this->links->findBySelector($selector);

        if (!$link instanceof SpaceAccessLinkInterface) {
            return null;
        }

        // Constant time, on the hash rather than the secret: a comparison that
        // returns early on the first wrong character tells somebody how much of
        // it they have right.
        if (!hash_equals($link->getHashedToken(), AbstractSpaceAccessLink::hashToken($token))) {
            return null;
        }

        if (!$link->isUsable(new DateTimeImmutable())) {
            return null;
        }

        // A space in the trash no longer answers its links, like an unknown
        // link; they resume as they were if it is restored.
        if ($link->getSpace()->isTrashed()) {
            return null;
        }

        return $link;
    }

    /**
     * Records that the space was opened.
     *
     * Written on every visit, and audited only on the first. A client who reads
     * their plan twice a day would otherwise fill the log with one line per
     * refresh, and the answer that matters is whether the mail ever arrived.
     */
    public function markOpened(SpaceAccessLinkInterface $link): void
    {
        $wasNeverOpened = !$link->getFirstOpenedAt() instanceof DateTimeImmutable;

        $link->markUsed(new DateTimeImmutable());
        $this->entityManager->flush();

        if ($wasNeverOpened) {
            $this->auditFirstOpened($link);
        }
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createLink(): SpaceAccessLinkInterface
    {
        return new SpaceAccessLink();
    }

    protected function auditIssued(SpaceAccessLinkInterface $link): void
    {
        $this->auditLogger->log('studio', 'space_access_link.issued', 'SpaceAccessLink', $link->getId(), $this->auditPayload($link));
    }

    protected function auditRevoked(SpaceAccessLinkInterface $link): void
    {
        $this->auditLogger->log('studio', 'space_access_link.revoked', 'SpaceAccessLink', $link->getId(), $this->auditPayload($link));
    }

    protected function auditDeleted(SpaceAccessLinkInterface $link): void
    {
        $this->auditLogger->log('studio', 'space_access_link.deleted', 'SpaceAccessLink', $link->getId(), $this->auditPayload($link));
    }

    protected function auditFirstOpened(SpaceAccessLinkInterface $link): void
    {
        $this->auditLogger->log('studio', 'space_access_link.opened', 'SpaceAccessLink', $link->getId(), $this->auditPayload($link));
    }

    /**
     * Structured payload logged with every audit entry.
     *
     * The selector is in it and the secret never is: the first identifies a row
     * somebody may need to find, and the second is the thing the whole design
     * exists to keep out of storage - a log is storage.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(SpaceAccessLinkInterface $link): array
    {
        return [
            'selector' => $link->getSelector(),
            'spaceId' => $link->getSpace()->getId(),
            'spaceName' => $link->getSpace()->getName(),
            'recipientEmail' => $link->getRecipientEmail(),
            'expiresAt' => $link->getExpiresAt()->format(DATE_ATOM),
            'canApprove' => $link->canApprove(),
            'canComment' => $link->canComment(),
            'canUpload' => $link->canUpload(),
        ];
    }
}
