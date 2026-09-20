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

    /** Ce que dure un aperçu. Assez pour regarder, trop peu pour oublier. */
    protected const int PREVIEW_MINUTES = 15;

    public function issue(
        CustomerSpaceInterface $space,
        string $recipientEmail,
        ?string $label,
        int $validForDays,
        bool $canApprove,
        bool $canComment,
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
            ->setCanUpload($canUpload)
            ->setCanSeeDrive($canSeeDrive)
            ->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', $days)));

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $this->auditIssued($link);

        return $link;
    }

    /**
     * Un aperçu de ce lien, ouvert pour quelques minutes.
     *
     * **Un vrai lien, parce qu'un faux ne montrerait rien.** Le jeton en clair
     * n'existe qu'à la création ; une page fabriquée avec un jeton inventé
     * s'affiche et ne répond à rien, donc ni le dossier Drive ni les fichiers
     * n'y apparaissent - c'est-à-dire précisément ce qu'on venait vérifier.
     *
     * Il recopie les droits pour que l'écran soit le même, **et n'écrit
     * rien** : ce refus-là ne tient pas aux droits mais à sa nature, et vit
     * dans le contrôleur public. Sans cette règle, un clic distrait sur
     * « Validé » enregistrerait une réponse au nom du client.
     *
     * Un seul à la fois par lien : le précédent est supprimé, ce qui évite
     * qu'une adresse encore valide traîne après qu'on a changé les droits
     * qu'elle était censée montrer.
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
            ->setCanUpload($source->canUpload())
            ->setCanSeeDrive($source->canSeeDrive())
            ->setPreviewOf($source)
            // Quelques minutes : le temps de regarder, pas celui d'oublier.
            ->setExpiresAt(new DateTimeImmutable(sprintf('+%d minutes', static::PREVIEW_MINUTES)));

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        // Pas d'audit « lien émis » : personne n'a reçu d'adresse, et une
        // ligne par coup d'œil noierait celles qui comptent.
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
