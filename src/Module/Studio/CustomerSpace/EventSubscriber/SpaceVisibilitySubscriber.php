<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\EventSubscriber;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
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
 * **La corbeille passe par ici aussi.** Un espace à la corbeille n'est vu de
 * personne ({@see SpaceVisibility::canSee()}), et un contenu à la corbeille,
 * avec son fil et ses fichiers, répond comme une fiche inconnue : chaque route
 * qui reçoit un contenu, un message ou un fichier d'un contenu le refuse sans
 * avoir à y penser. La corbeille agit sur eux par leur identifiant, jamais en
 * argument.
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
            if ($argument instanceof CustomerSpaceInterface && !$this->visibility->canSee($argument)) {
                throw new NotFoundHttpException();
            }

            $item = match (true) {
                $argument instanceof SpaceContentItemInterface => $argument,
                $argument instanceof SpaceContentCommentInterface, $argument instanceof SpaceContentAttachmentInterface => $argument->getItem(),
                default => null,
            };

            if ($item instanceof SpaceContentItemInterface && $item->isTrashed()) {
                throw new NotFoundHttpException();
            }
        }
    }
}
