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
 * Les liens de lecture d'un livrable : en donner un, en retirer un.
 *
 * Un livrable d'espace et un livrable de Studio s'envoient de la même façon ;
 * seul change qui a le droit de le faire, et c'est au contrôleur d'en juger.
 */
readonly class DeliverableLinkIssuer
{
    /** Un an : la règle de tous les liens de Studio, {@see ShareLinkRules}. */
    public const int MAX_EXPIRY_DAYS = ShareLinkRules::MAX_EXPIRY_DAYS;

    /** Ce que lit bcrypt, en octets : {@see ShareLinkRules::PASSWORD_MAX_BYTES}. */
    public const int PASSWORD_MAX_BYTES = ShareLinkRules::PASSWORD_MAX_BYTES;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeliverableLinkRepository $links,
        private AuditLogger $auditLogger,
    ) {}

    /** Le lien qu'on crée, à un seul endroit : un projet qui étend l'entité surcharge ceci. */
    protected function instantiate(DeliverableInterface $deliverable): DeliverableLinkInterface
    {
        return new DeliverableLink($deliverable);
    }

    /**
     * Ce que la création d'un lien refuserait dans cet envoi.
     *
     * Une durée qu'on ne sait pas lire ne devient pas « sans fin » : un lien
     * qui n'expire jamais est le contraire de ce qu'on a voulu poser en
     * tapant une durée. Absente ou nulle, elle veut bien dire « sans fin ».
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string> les erreurs par champ, vide quand tout passe
     */
    public function errors(array $payload): array
    {
        return ShareLinkRules::errors($payload);
    }

    /**
     * Le mot de passe tel qu'il est retenu, et tel qu'on le compare : sans les
     * espaces de bord, des deux côtés.
     *
     * @param array<string, mixed> $payload
     */
    public static function password(array $payload): string
    {
        return ShareLinkRules::password($payload);
    }

    /**
     * Une adresse de plus : un intitulé pour s'y retrouver, une expiration et
     * un mot de passe au choix.
     *
     * @param array<string, mixed> $payload
     */
    public function issue(DeliverableInterface $deliverable, array $payload): void
    {
        $link = $this->instantiate($deliverable);
        $link->setLabel(is_string($payload['label'] ?? null) ? mb_substr(mb_trim($payload['label']), 0, 120) : '');

        // Validée par {@see self::errors()} : ici, la durée est un entier de 1
        // à l'année, ou rien.
        $days = $payload['expiresInDays'] ?? null;
        if (is_int($days)) {
            $link->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', $days)));
        }

        // `password_hash`, comme pour une présentation : c'est une phrase
        // choisie par quelqu'un, et les gens réutilisent leurs phrases.
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

        $this->auditLogger->log('studio', 'deliverable_link.revoked', 'DeliverableLink', $link->getId(), $this->auditPayload($deliverable, ['label' => $link->getLabel()]));

        return true;
    }

    /**
     * Supprimer l'adresse, seulement si personne ne l'a jamais ouverte : il n'y
     * a alors rien à se rappeler. Une adresse ouverte se révoque, et sa ligne
     * garde qui a pu lire. Vérifié contre le livrable reçu, comme la révocation.
     *
     * @return bool|null null quand le lien n'est pas à ce livrable, faux quand il a déjà servi
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
     * Donner ou retirer une adresse, c'est ouvrir ou fermer un accès à un
     * document client : une ligne du journal, comme pour l'accès d'un espace.
     * Le jeton n'y figure jamais. Chaque appel écrit son action en toutes
     * lettres, pour le test des libellés du journal.
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
