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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function hash_equals;
use function hash_hmac;
use function mb_rtrim;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function random_int;
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

    /**
     * The random end of a short address: no 0/o, 1/l/i, which a client copying
     * from a card would confuse. Six of thirty-one is 887 million names per
     * readable part, behind a rate limit: nobody walks that.
     */
    protected const string ALIAS_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    protected const int ALIAS_SUFFIX_LENGTH = 6;

    /** The readable part, kept short enough to be read aloud. */
    protected const int ALIAS_NAME_LENGTH = 40;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly SpaceAccessLinkRepository $accessLinkRepository,
        // Signs the token a short address redirects with (10/10/2026). Last and
        // defaulted, so a subclass written before it still constructs.
        #[Autowire(param: 'kernel.secret')]
        protected readonly string $secret = '',
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
        bool $canSeeContracts = false,
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
            ->setCanSeeContracts($canSeeContracts)
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
        $existing = $this->accessLinkRepository->findPreviewOf($source);

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
            ->setCanSeeContracts($source->canSeeContracts())
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
     * Gives a link its full validity again, counted from today.
     *
     * The address does not change, so the client's bookmark and the mails
     * already sent keep working: renewing an access used to mean issuing a
     * new link and sending it again, which is why accesses were left to run
     * out. A revoked link is a decision and stays closed; an expired one can
     * be brought back, which is the case this is most often for.
     */
    public function extend(SpaceAccessLinkInterface $link): void
    {
        if (null !== $link->getRevokedAt() || $link->isPreview()) {
            return;
        }

        $link->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', static::DEFAULT_VALID_DAYS)));
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'space_access_link.extended', 'SpaceAccessLink', $link->getId(), $this->auditPayload($link));
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
        $link = $this->accessLinkRepository->findBySelector($selector);

        if (!$link instanceof SpaceAccessLinkInterface) {
            return null;
        }

        // Constant time, on the hash rather than the secret: a comparison that
        // returns early on the first wrong character tells somebody how much of
        // it they have right. The short address's token opens the same page
        // (10/10/2026), as long as the short address exists.
        $aliasToken = $this->aliasToken($link);
        $mailToken = $this->mailToken($link);
        if (!hash_equals($link->getHashedToken(), AbstractSpaceAccessLink::hashToken($token))
            && (null === $aliasToken || !hash_equals($aliasToken, $token))
            && (null === $mailToken || !hash_equals($mailToken, $token))) {
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

    /**
     * Gives a link a short address (10/10/2026): `name` made readable, and a
     * random end so that knowing the client's name is not enough to open their
     * space. Replaces the previous one, which stops answering.
     *
     * @return string the name, « fournier-k7m2q9 »
     */
    public function giveAlias(SpaceAccessLinkInterface $link, string $name): string
    {
        $readable = $this->aliasName($name);
        if ('' === $readable) {
            $readable = $this->aliasName($link->getSpace()->getCustomer()->getLegalName());
        }

        if ('' === $readable) {
            $readable = 'espace';
        }

        do {
            $suffix = '';
            for ($index = 0; $index < static::ALIAS_SUFFIX_LENGTH; ++$index) {
                $suffix .= static::ALIAS_ALPHABET[random_int(0, mb_strlen(static::ALIAS_ALPHABET) - 1)];
            }

            $alias = $readable.'-'.$suffix;
        } while ($this->accessLinkRepository->findByAliasHash(AbstractSpaceAccessLink::hashToken($alias)) instanceof SpaceAccessLinkInterface);

        $link->setAlias($alias);
        $this->entityManager->flush();
        $this->auditLogger->log('studio', 'space_access_link.alias_given', 'SpaceAccessLink', $link->getId(), $this->auditPayload($link));

        return $alias;
    }

    /** Takes the short address away: it stops answering at once. */
    public function removeAlias(SpaceAccessLinkInterface $link): void
    {
        if (null === $link->getAlias()) {
            return;
        }

        $link->setAlias(null);
        $this->entityManager->flush();
        $this->auditLogger->log('studio', 'space_access_link.alias_removed', 'SpaceAccessLink', $link->getId(), $this->auditPayload($link));
    }

    /**
     * The link a short address opens, under the same conditions as the long
     * one: not revoked, not expired, not a preview, its space not in the trash.
     */
    public function resolveAlias(string $alias): ?SpaceAccessLinkInterface
    {
        $link = $this->accessLinkRepository->findByAliasHash(AbstractSpaceAccessLink::hashToken(mb_strtolower($alias)));
        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview()) {
            return null;
        }

        if (!$link->isUsable(new DateTimeImmutable()) || $link->getSpace()->isTrashed()) {
            return null;
        }

        return $link;
    }

    /**
     * The token a short address redirects with, computed and never stored.
     *
     * Signed with the application's secret over the selector and the short
     * address, so it changes when the short address does and dies with it: no
     * second secret to keep, and nothing in the database that opens the space.
     */
    public function aliasToken(SpaceAccessLinkInterface $link): ?string
    {
        $aliasHash = $link->getAliasHash();
        if (null === $aliasHash || '' === $this->secret) {
            return null;
        }

        return hash_hmac('sha256', 'space-alias|'.$link->getSelector().'|'.$aliasHash, $this->secret);
    }

    /**
     * The token the application's own mails carry, computed and never stored.
     *
     * **The mails need an address they can write again.** The long address
     * exists once, at creation, and the review invitation had to mint a new
     * link to have one to send - revoking the address the client had
     * bookmarked each time. A digest written every half hour cannot do that.
     *
     * Same construction as {@see aliasToken()}: signed with the application's
     * secret over the selector and the stored hash, so it opens what the long
     * address opens, under the same conditions, changes when the link is
     * reissued and dies when it is revoked or expires. Nothing in the
     * database opens the space without the application's secret.
     */
    public function mailToken(SpaceAccessLinkInterface $link): ?string
    {
        if ('' === $this->secret || $link->isPreview()) {
            return null;
        }

        return hash_hmac('sha256', 'space-mail|'.$link->getSelector().'|'.$link->getHashedToken(), $this->secret);
    }

    /** Lowercase ASCII words joined by dashes, cut at a word. */
    protected function aliasName(string $name): string
    {
        $slug = mb_strtolower(new AsciiSlugger()->slug(mb_trim($name))->toString());
        $slug = mb_substr($slug, 0, static::ALIAS_NAME_LENGTH);

        return mb_trim(mb_rtrim($slug, '-'), '-');
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
