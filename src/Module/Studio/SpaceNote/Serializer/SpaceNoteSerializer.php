<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Serializer;

use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(SpaceNoteSerializerInterface::class)]
class SpaceNoteSerializer implements SpaceNoteSerializerInterface
{
    /**
     * Une note, telle que les deux vues la lisent.
     *
     * Le corps entier voyage, même pour la vue en post-it qui n'en montre que
     * le début : les deux vues sont deux lectures du même mur, changer de vue
     * ne doit rien coûter, et ouvrir une note pour la lire encore moins. Un mur
     * de notes est petit - c'est un carnet, pas une archive.
     *
     * @return array<string, mixed>
     */
    public function serialize(SpaceNoteInterface $note): array
    {
        return [
            'id' => $note->getId(),
            'title' => $note->getTitle(),
            'body' => $note->getBody(),
            'colourSlot' => $note->getColourSlot(),
            'pinned' => $note->isPinned(),
            // Ce qui separe l'onglet « partagees » de l'onglet « personnelles ».
            // Les notes personnelles des autres ne sont pas ici : elles ne sont
            // jamais sorties du serveur.
            'visibility' => $note->getVisibility()->value,
            // Le nom durable, pas la relation : un compte supprimé ne doit pas
            // transformer une note en note que personne n'a prise.
            'author' => $note->getAuthorLabel(),
            // **Un booléen, pas l'identifiant.** L'écran a besoin de savoir
            // qu'un bouton « mettre à jour » a un sens sur cette note, et rien
            // de plus ; l'identifiant d'un document Craft ne veut rien dire
            // pour qui n'a pas Craft, et le client, lui, n'en a pas.
            'fromCraft' => null !== $note->getCraftDocumentId(),
            'createdAt' => $note->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $note->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
