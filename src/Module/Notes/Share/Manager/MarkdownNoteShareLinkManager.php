<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteShareLinkRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opening, closing and redeeming the addresses that reach a note.
 *
 * Mirrors `PlanningShareLinkManager`, deliberately: the two features answer the
 * same question about different things, and one of them being subtly different
 * would be a reason to distrust both.
 */
class MarkdownNoteShareLinkManager implements MarkdownNoteShareLinkManagerInterface
{
    /**
     * How stale `lastUsedAt` may get before it is worth a write.
     *
     * A guest reading a shared branch clicks between notes, and stamping the row
     * on every page would be an UPDATE per request for a column nobody reads to
     * the minute. An hour still answers both questions the column exists for -
     * "was it ever opened" and "roughly when last".
     */
    private const int USE_STAMP_INTERVAL_SECONDS = 3600;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly MarkdownNoteShareLinkRepository $links,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(
        MarkdownNoteInterface $note,
        bool $includeDescendants,
        ?string $recipientEmail = null,
        string $label = '',
        ?DateTimeImmutable $expiresAt = null,
    ): MarkdownNoteShareLinkInterface {
        $link = $this->createLink();
        $link->setNote($note);
        $link->setIncludeDescendants($includeDescendants);
        $link->setRecipientEmail($recipientEmail);
        $link->setLabel($label);
        $link->setExpiresAt($expiresAt);

        $this->entityManager->persist($link);

        // The token is never logged. An audit row is read by more people than the
        // link was ever shared with, and a secret in it is a wider grant than the
        // one being recorded.
        $this->auditLogger->log('notes_markdown', 'share_link.created', 'MarkdownNoteShareLink', null, [
            'note' => $note->getId(),
            'includeDescendants' => $includeDescendants,
            'recipient' => $recipientEmail,
            'expiresAt' => $expiresAt?->format('c'),
        ]);

        $this->entityManager->flush();

        return $link;
    }

    public function revoke(MarkdownNoteShareLinkInterface $link, ?DateTimeImmutable $at = null): void
    {
        $link->revoke($at ?? new DateTimeImmutable());

        $this->auditLogger->log('notes_markdown', 'share_link.revoked', 'MarkdownNoteShareLink', $link->getId(), [
            'note' => $link->getNote()->getId(),
            'recipient' => $link->getRecipientEmail(),
        ]);

        $this->entityManager->flush();
    }

    /**
     * The link behind a token, if it still works, stamping that it was used.
     *
     * Every way of failing gives the same null: a token that never existed, one
     * that expired and one that was revoked are one answer to whoever is asking,
     * because telling them apart tells a stranger which guesses were close.
     */
    public function resolveUsable(string $token, ?DateTimeImmutable $now = null): ?MarkdownNoteShareLinkInterface
    {
        $now ??= new DateTimeImmutable();

        $link = $this->links->findByToken($token);

        if (!$link instanceof MarkdownNoteShareLinkInterface || !$link->isUsableAt($now)) {
            return null;
        }

        $this->stampUse($link, $now);

        return $link;
    }

    protected function createLink(): MarkdownNoteShareLinkInterface
    {
        return new MarkdownNoteShareLink();
    }

    private function stampUse(MarkdownNoteShareLinkInterface $link, DateTimeImmutable $now): void
    {
        $last = $link->getLastUsedAt();

        if ($last instanceof DateTimeImmutable
            && $now->getTimestamp() - $last->getTimestamp() < self::USE_STAMP_INTERVAL_SECONDS) {
            return;
        }

        $link->setLastUsedAt($now);
        $this->entityManager->flush();
    }
}
