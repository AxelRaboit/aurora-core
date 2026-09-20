<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\EventSubscriber;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Un espace dont on n'est pas membre n'existe pas.
 *
 * **Posé sur les arguments plutôt que dans chaque contrôleur**, et c'est tout
 * l'intérêt. Une dizaine de contrôleurs reçoivent un espace aujourd'hui, la
 * discussion, les notes, les fichiers, le Drive, l'accès client ; en ajouter
 * un onzième est une chose qu'on fait sans y penser, et la ligne de contrôle
 * est exactement ce qu'on oublie alors. Ici, le contrôle arrive avec
 * l'argument.
 *
 * **Un 404 et non un 403.** Un refus explicite dirait à quelqu'un qui tâtonne
 * que l'espace numéro douze existe et appartient à un autre client. Du point
 * de vue d'un équipier qui n'en est pas membre, il n'y a rien à cette adresse.
 *
 * Les routes publiques ne passent pas par là : elles résolvent un lien et ne
 * reçoivent jamais d'espace en argument. Leur contrôle, c'est le lien.
 */
final readonly class SpaceVisibilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SpaceVisibility $visibility,
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

        foreach ($event->getArguments() as $argument) {
            if (!$argument instanceof CustomerSpaceInterface) {
                continue;
            }

            if (!$this->visibility->canSee($argument)) {
                throw new NotFoundHttpException();
            }
        }
    }
}
