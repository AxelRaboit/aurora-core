<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function is_int;
use function is_string;
use function mb_substr;
use function mb_trim;
use function min;
use function password_hash;
use function sprintf;

use const PASSWORD_DEFAULT;

/**
 * Les liens de lecture d'un livrable : en donner un, en retirer un.
 *
 * Un livrable d'espace et un livrable de Studio s'envoient de la même façon ;
 * seul change qui a le droit de le faire, et c'est au contrôleur d'en juger.
 */
final readonly class DeliverableLinkIssuer
{
    /** Un an : au-delà, une date d'expiration ne protège plus grand-chose. */
    public const int MAX_EXPIRY_DAYS = 365;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeliverableLinkRepository $links,
    ) {}

    /**
     * Une adresse de plus : un intitulé pour s'y retrouver, une expiration et
     * un mot de passe au choix.
     *
     * @param array<string, mixed> $payload
     */
    public function issue(DeliverableInterface $deliverable, array $payload): void
    {
        $link = new DeliverableLink($deliverable);
        $link->setLabel(is_string($payload['label'] ?? null) ? mb_substr(mb_trim($payload['label']), 0, 120) : '');

        $days = is_int($payload['expiresInDays'] ?? null) ? $payload['expiresInDays'] : null;
        if (null !== $days && $days > 0) {
            $link->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', min($days, self::MAX_EXPIRY_DAYS))));
        }

        // `password_hash`, comme pour une présentation : c'est une phrase
        // choisie par quelqu'un, et les gens réutilisent leurs phrases.
        $password = is_string($payload['password'] ?? null) ? mb_trim($payload['password']) : '';
        if ('' !== $password) {
            $link->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }

    /**
     * Révoquer date la ligne ; elle n'est jamais supprimée.
     *
     * Vérifié contre le livrable reçu : le lien d'un autre livrable ne se
     * révoque pas par celui-ci. Faux quand il n'y a rien à révoquer ici.
     */
    public function revoke(DeliverableInterface $deliverable, int $linkId): bool
    {
        $link = $this->links->find($linkId);
        if (null === $link || $link->getDeliverable()->getId() !== $deliverable->getId()) {
            return false;
        }

        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }
}
