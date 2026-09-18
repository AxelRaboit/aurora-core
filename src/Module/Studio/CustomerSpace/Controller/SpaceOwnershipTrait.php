<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Controller;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;

/**
 * La seule chose qui sépare le tableau de deux clients.
 *
 * Chaque écran d'un espace nomme l'espace dans son adresse, puis reçoit la
 * carte, la note, le message ou le fichier comme sa propre entité par son
 * identifiant. Rien n'empêche alors une requête fabriquée de désigner la note
 * d'un client sous l'espace d'un autre : c'est cette ligne qui l'empêche, et
 * elle vivait recopiée dans cinq contrôleurs.
 *
 * **404 et pas 403.** Distinguer les deux dirait à qui tient une adresse ce
 * qu'il tient, ce que les routes publiques de ce module refusent déjà de dire.
 */
trait SpaceOwnershipTrait
{
    /**
     * @param int|null $ownerId l'espace auquel appartient ce qu'on a reçu
     */
    protected function assertOwned(CustomerSpace $space, ?int $ownerId): void
    {
        if ($ownerId !== $space->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
