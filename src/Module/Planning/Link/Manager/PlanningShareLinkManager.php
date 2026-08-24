<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Planning\Link\Entity\PlanningShareLink;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Link\Repository\PlanningShareLinkRepository;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function count;

/**
 * Makes, closes and resolves the addresses that reach a calendar without an
 * account.
 *
 * The interesting method is `resolveUsable`, which is what every guest request
 * goes through. It answers null for a token that never existed, one that has
 * expired and one that was revoked alike - deliberately, because the caller must
 * not be able to tell them apart on the way out either. A page that said "expired"
 * for one and "not found" for the other would confirm which random strings had once
 * been real.
 */
class PlanningShareLinkManager implements PlanningShareLinkManagerInterface
{
    /**
     * How stale `lastUsedAt` is allowed to get before it is worth a write.
     *
     * A subscribed calendar application polls every few minutes for years, and
     * stamping the row on each poll would be an UPDATE per request for a column
     * nobody reads to the minute. An hour keeps both answers the column exists for
     * - "has this ever been opened" and "roughly when last" - at a write a caller
     * will not notice.
     */
    private const int USE_STAMP_INTERVAL_SECONDS = 3600;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly PlanningShareLinkRepository $links,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param list<PlanningInterface> $calendars
     */
    public function create(
        array $calendars,
        string $label,
        PlanningShareLinkModeEnum $mode,
        ?DateTimeImmutable $expiresAt = null,
    ): PlanningShareLinkInterface {
        $link = $this->createLink();
        $link->setLabel($label);
        $link->setMode($mode);
        $link->setExpiresAt($expiresAt);

        foreach ($calendars as $calendar) {
            $link->addCalendar($calendar);
        }

        $this->entityManager->persist($link);

        // The token is never logged. An audit row is read by more people than the
        // link was ever shared with, and a secret in it is a wider grant than the
        // one being recorded.
        $this->auditLogger->log('planning', 'share_link.created', 'PlanningShareLink', null, [
            'label' => $label,
            'mode' => $mode->value,
            'calendars' => count($calendars),
            'expiresAt' => $expiresAt?->format('c'),
        ]);

        $this->entityManager->flush();

        return $link;
    }

    public function revoke(PlanningShareLinkInterface $link, ?DateTimeImmutable $at = null): void
    {
        $link->revoke($at ?? new DateTimeImmutable());

        $this->auditLogger->log('planning', 'share_link.revoked', 'PlanningShareLink', $link->getId(), [
            'label' => $link->getLabel(),
            'mode' => $link->getMode()->value,
        ]);

        $this->entityManager->flush();
    }

    /**
     * The link behind a token, if it still works, stamping that it was used.
     *
     * The mode is part of the question rather than checked afterwards: a web token
     * must not answer an `.ics` request and the other way round, or the address
     * somebody sent to one guest is also a permanent subscription - a wider grant
     * than they chose.
     */
    public function resolveUsable(
        string $token,
        PlanningShareLinkModeEnum $mode,
        ?DateTimeImmutable $now = null,
    ): ?PlanningShareLinkInterface {
        $now ??= new DateTimeImmutable();

        $link = $this->links->findByToken($token);

        if (!$link instanceof PlanningShareLinkInterface || $mode !== $link->getMode()) {
            return null;
        }

        if (!$link->isUsableAt($now)) {
            return null;
        }

        $this->stampUse($link, $now);

        return $link;
    }

    protected function createLink(): PlanningShareLinkInterface
    {
        return new PlanningShareLink();
    }

    /**
     * Records the visit, at most once an hour.
     *
     * Flushed here rather than left to the caller: this is reached from a route
     * that otherwise only reads, and a manager that mutates and waits for somebody
     * else to flush is how a stamp goes missing.
     */
    private function stampUse(PlanningShareLinkInterface $link, DateTimeImmutable $now): void
    {
        $last = $link->getLastUsedAt();

        if ($last instanceof DateTimeImmutable && ($now->getTimestamp() - $last->getTimestamp()) < self::USE_STAMP_INTERVAL_SECONDS) {
            return;
        }

        $link->markUsed($now);
        $this->entityManager->flush();
    }
}
