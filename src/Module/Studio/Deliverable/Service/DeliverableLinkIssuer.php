<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLinkInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Sharing\ShareLinkRules;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function is_int;
use function is_string;
use function mb_substr;
use function mb_trim;
use function password_hash;
use function sprintf;

use const PASSWORD_DEFAULT;

/**
 * A deliverable's reading links: give one, revoke one.
 *
 * A space deliverable and a Studio deliverable are sent the same way; only
 * who has the right to do it changes, and the controller is the judge of
 * that.
 */
readonly class DeliverableLinkIssuer
{
    /** One year: the rule for every Studio link, {@see ShareLinkRules}. */
    public const int MAX_EXPIRY_DAYS = ShareLinkRules::MAX_EXPIRY_DAYS;

    /** What bcrypt reads, in bytes: {@see ShareLinkRules::PASSWORD_MAX_BYTES}. */
    public const int PASSWORD_MAX_BYTES = ShareLinkRules::PASSWORD_MAX_BYTES;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeliverableLinkRepository $links,
        private AuditLogger $auditLogger,
    ) {}

    /** The link being created, in one place: a project that extends the entity overrides this. */
    protected function instantiate(DeliverableInterface $deliverable): DeliverableLinkInterface
    {
        return new DeliverableLink($deliverable);
    }

    /**
     * What creating a link would refuse in this request.
     *
     * A duration that cannot be read does not become "endless": a link that
     * never expires is the opposite of what typing a duration meant to set.
     * Absent or null, it does mean "endless".
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string> the errors by field, empty when everything passes
     */
    public function errors(array $payload): array
    {
        return ShareLinkRules::errors($payload);
    }

    /**
     * The password as it is stored, and as it is compared: without leading
     * and trailing spaces, on both sides.
     *
     * @param array<string, mixed> $payload
     */
    public static function password(array $payload): string
    {
        return ShareLinkRules::password($payload);
    }

    /**
     * One more address: a label to find your way, an optional expiry and an
     * optional password.
     *
     * @param array<string, mixed> $payload
     */
    public function issue(DeliverableInterface $deliverable, array $payload): void
    {
        $link = $this->instantiate($deliverable);
        $link->setLabel(is_string($payload['label'] ?? null) ? mb_substr(mb_trim($payload['label']), 0, 120) : '');

        // Validated by {@see self::errors()}: here, the duration is an integer
        // from 1 to a year, or nothing.
        $days = $payload['expiresInDays'] ?? null;
        if (is_int($days)) {
            $link->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', $days)));
        }

        // `password_hash`, as for a presentation: it is a phrase someone
        // chose, and people reuse their phrases.
        $password = self::password($payload);
        if ('' !== $password) {
            $link->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'deliverable_link.issued', 'DeliverableLink', $link->getId(), $this->auditPayload($deliverable, [
            'label' => $link->getLabel(),
            'expires' => $link->getExpiresAt() instanceof DateTimeImmutable,
            'locked' => $link->isLocked(),
        ]));
    }

    /**
     * Revoking dates the row; it is never deleted.
     *
     * Checked against the deliverable received: another deliverable's link
     * cannot be revoked through this one. False when there is nothing to
     * revoke here.
     */
    public function revoke(DeliverableInterface $deliverable, int $linkId): bool
    {
        $link = $this->links->find($linkId);
        if (null === $link || $link->getDeliverable()->getId() !== $deliverable->getId()) {
            return false;
        }

        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'deliverable_link.revoked', 'DeliverableLink', $link->getId(), $this->auditPayload($deliverable, ['label' => $link->getLabel()]));

        return true;
    }

    /**
     * Hide from the list a link that no longer opens anything (revoked or
     * expired). Its row stays: it still says who may have read. A live link
     * cannot be hidden, it has to be revoked first.
     *
     * @return bool|null null when the link does not belong to this deliverable, false when it is still live
     */
    public function hide(DeliverableInterface $deliverable, int $linkId): ?bool
    {
        $link = $this->links->find($linkId);
        if (null === $link || $link->getDeliverable()->getId() !== $deliverable->getId()) {
            return null;
        }

        $now = new DateTimeImmutable();
        if (!ShareLinkRules::canBeHidden($link->isUsable($now))) {
            return false;
        }

        $link->hide($now);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'deliverable_link.hidden', 'DeliverableLink', $link->getId(), $this->auditPayload($deliverable, ['label' => $link->getLabel()]));

        return true;
    }

    /**
     * Delete the address, only if nobody has ever opened it: there is then
     * nothing to remember. An opened address is revoked, and its row keeps
     * who may have read. Checked against the deliverable received, like
     * revocation.
     *
     * @return bool|null null when the link does not belong to this deliverable, false when it has already been used
     */
    public function delete(DeliverableInterface $deliverable, int $linkId): ?bool
    {
        $link = $this->links->find($linkId);
        if (null === $link || $link->getDeliverable()->getId() !== $deliverable->getId()) {
            return null;
        }

        if (!ShareLinkRules::canBeDeleted($link->getOpenCount())) {
            return false;
        }

        $this->auditLogger->log('studio', 'deliverable_link.deleted', 'DeliverableLink', $link->getId(), $this->auditPayload($deliverable, ['label' => $link->getLabel()]));

        $this->entityManager->remove($link);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Giving or revoking an address opens or closes access to a client
     * document: an audit log line, as for access to a space. The token never
     * appears in it. Each call writes its action out in full, for the audit
     * label test.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function auditPayload(DeliverableInterface $deliverable, array $extra): array
    {
        return [
            'deliverable' => $deliverable->getId(),
            'title' => $deliverable->getTitle(),
            'space' => $deliverable->getSpace()?->getId(),
            ...$extra,
        ];
    }
}
