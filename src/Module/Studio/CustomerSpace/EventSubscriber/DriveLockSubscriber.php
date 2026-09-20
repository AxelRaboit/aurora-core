<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\EventSubscriber;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function str_starts_with;

/**
 * Un onglet Drive fermé ferme aussi ses adresses.
 *
 * **Masquer un onglet n'a jamais fermé une route.** Le mot de passe ne
 * protégerait qu'un bouton si le téléchargement d'un fichier, l'archive ou
 * l'import restaient joignables en tapant l'adresse : la protection doit être
 * du côté qui répond, pas de celui qui dessine.
 *
 * Posé sur les arguments et reconnu au nom de la route, pour la raison que
 * {@see SpaceVisibilitySubscriber} donne : la prochaine route du Drive sera
 * couverte sans que personne y pense. C'est déjà arrivé trois fois sur ce
 * module, où chaque geste nouveau a ajouté sa route.
 *
 * **La liste fait exception**, et délibérément : elle répond `locked` pour que
 * l'écran sache demander le mot de passe. Un 404 la rendrait indiscernable
 * d'un espace sans Drive, et l'écran afficherait « aucun dossier » à quelqu'un
 * qui n'a qu'un mot de passe à saisir.
 */
final readonly class DriveLockSubscriber implements EventSubscriberInterface
{
    /** Toutes les routes du Drive d'un espace portent ce préfixe. */
    private const string ROUTE_PREFIX = 'workspace_space_drive';

    /** Celle qui sait dire « fermé » proprement, et se garde elle-même. */
    private const string LISTING_ROUTE = 'workspace_space_drive_list';

    public function __construct(
        private DriveLock $lock,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments'];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');

        if (!str_starts_with($route, self::ROUTE_PREFIX) || self::LISTING_ROUTE === $route) {
            return;
        }

        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof CustomerSpaceInterface && $this->lock->isClosedFor($argument)) {
                throw new NotFoundHttpException();
            }
        }
    }
}
