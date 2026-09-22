<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManager;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Demander à un client d'aller relire son plan de contenu.
 *
 * **Déclenché à la main, et c'est le point.** Le studio prépare une semaine de
 * publications en une fois ; un envoi automatique à chaque carte devenue
 * visible remplirait la boîte du client pendant que le lot se construit
 * encore. Celui qui sait quand le lot est prêt est celui qui l'a préparé.
 *
 * **Un lien neuf par destinataire, l'ancien révoqué.** Le jeton d'un lien
 * n'existe en clair qu'à sa création : seul son condensé est stocké, pour
 * qu'une base volée n'ouvre pas le plan de contenu d'un client. On ne peut donc
 * pas remettre dans un courriel l'adresse d'un lien déjà émis, et il faut en
 * émettre un. Révoquer le précédent est ce qui évite qu'un client accumule six
 * adresses ouvertes après six invitations : il en a toujours exactement une
 * valide, la dernière reçue.
 *
 * **Rien ne part s'il n'y a rien à relire.** Un courriel annonçant zéro
 * publication en attente est un courriel qui apprend à ignorer les suivants.
 */
final readonly class SpaceReviewInviter
{
    public function __construct(
        private SpaceAccessLinkRepository $links,
        private SpaceAccessLinkManagerInterface $linkManager,
        private SpaceContentItemRepository $items,
        private MailService $mail,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    /**
     * Écrit à tous ceux qui peuvent répondre dans cet espace.
     *
     * @return array{awaiting: int, notified: int} ce qui attendait, et combien
     *                                             de personnes ont été prévenues
     */
    public function invite(CustomerSpaceInterface $space): array
    {
        $awaiting = $this->items->countAwaitingApproval($space);

        if (0 === $awaiting) {
            return ['awaiting' => 0, 'notified' => 0];
        }

        $now = new DateTimeImmutable();
        $notified = 0;

        foreach ($this->links->findApproversForSpace($space, $now) as $previous) {
            $fresh = $this->reissue($previous);

            try {
                $this->write($space, $fresh, $awaiting);
            } catch (Throwable $exception) {
                // **Le courriel d'abord, la révocation ensuite.** Un serveur de
                // messagerie qui ne répond pas laisserait sinon le client sans
                // adresse valide et sans le message qui lui en donnait une
                // neuve, c'est-à-dire dehors et sans le savoir. Ici son
                // ancienne adresse continue de fonctionner, et c'est la neuve,
                // que personne n'a reçue, qui est refermée.
                $this->linkManager->revoke($fresh);
                $this->logger->error('Space review invitation could not be sent to {email}: {reason}', [
                    'email' => $previous->getRecipientEmail(),
                    'reason' => $exception->getMessage(),
                ]);

                continue;
            }

            $this->linkManager->revoke($previous);
            ++$notified;
        }

        return ['awaiting' => $awaiting, 'notified' => $notified];
    }

    /**
     * Le même lien, en neuf.
     *
     * Les droits sont recopiés : l'invitation ne change pas ce que la personne
     * a le droit de faire, elle lui redonne seulement une adresse. La validité
     * repart du délai par défaut plutôt que de ce qu'il restait au précédent,
     * sans quoi une invitation envoyée la veille d'une expiration donnerait un
     * jour pour répondre.
     */
    private function reissue(SpaceAccessLinkInterface $previous): SpaceAccessLinkInterface
    {
        return $this->linkManager->issue(
            $previous->getSpace(),
            $previous->getRecipientEmail(),
            $previous->getLabel(),
            SpaceAccessLinkManager::DEFAULT_VALID_DAYS,
            $previous->canApprove(),
            $previous->canComment(),
            $previous->canChat(),
            $previous->canUpload(),
            $previous->canSeeDrive(),
        );
    }

    private function write(CustomerSpaceInterface $space, SpaceAccessLinkInterface $link, int $awaiting): void
    {
        $this->mail->send(
            to: $link->getRecipientEmail(),
            subjectKey: 'studio.email.space_review.subject',
            template: '@Studio/email/space_review.html.twig',
            context: [
                'space' => $space,
                'awaiting' => $awaiting,
                'url' => $this->urlGenerator->generate(
                    'public_space_show',
                    [
                        'selector' => $link->getSelector(),
                        // Lisible une seule fois, à la création : c'est
                        // exactement pour ce message qu'on vient d'en créer un.
                        'token' => $link->getPlainToken(),
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'expiresAt' => $link->getExpiresAt(),
            ],
            subjectParams: ['{space}' => $space->getName(), '{count}' => (string) $awaiting],
        );
    }
}
