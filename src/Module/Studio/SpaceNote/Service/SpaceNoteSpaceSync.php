<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Service;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Manager\NoteSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;

/**
 * Tient l'espace de notes d'un espace client dans le pas de son équipe.
 *
 * **L'équipe de l'espace client fait foi.** Qui est sur l'espace écrit dans
 * ses notes ; qui le quitte n'y entre plus. Le référent gère l'espace de notes
 * (il en vide la corbeille), un membre y écrit. Personne d'autre n'est
 * inscrit, et l'écran des notes refuse qu'on y inscrive quelqu'un à la main :
 * une inscription posée là-bas serait défaite au prochain enregistrement d'ici.
 *
 * **Aucun privilège n'est donné.** Un membre de l'équipe qui n'a pas le droit
 * d'utiliser les notes (`notes.markdown.use`) est inscrit quand même, et ne
 * voit pas l'onglet : lui ouvrir le module serait une décision qui appartient
 * à qui règle les droits, pas un effet de bord de la composition d'une équipe.
 *
 * Rien ne se passe tant que l'espace de notes n'existe pas : il est ouvert la
 * première fois que quelqu'un en a besoin, par {@see SpaceNoteSpaceProvider},
 * qui le synchronise à ce moment-là.
 */
final readonly class SpaceNoteSpaceSync
{
    public function __construct(
        private NoteSpaceManagerInterface $noteSpaces,
    ) {}

    public function sync(CustomerSpaceInterface $space): void
    {
        $noteSpace = $space->getNoteSpace();

        if (!$noteSpace instanceof NoteSpaceInterface || !$noteSpace->isManaged()) {
            return;
        }

        $members = [];
        foreach ($space->getMembers() as $member) {
            $members[] = [
                'user' => $member->getUser(),
                'role' => self::roleFor($member->getRole()),
            ];
        }

        $this->noteSpaces->syncManaged($noteSpace, $space->getName(), $members);
    }

    /**
     * L'espace client s'en va : son espace de notes part à la corbeille,
     * redevenu un espace ordinaire que les administrateurs peuvent faire
     * revenir. Les notes ne partent pas avec un client.
     */
    public function release(CustomerSpaceInterface $space): void
    {
        $noteSpace = $space->getNoteSpace();

        if (!$noteSpace instanceof NoteSpaceInterface || !$noteSpace->isManaged()) {
            return;
        }

        $this->noteSpaces->releaseManaged($noteSpace);
    }

    /** Le référent gère, un membre écrit. */
    public static function roleFor(CustomerSpaceMemberRoleEnum $role): NoteSpaceRoleEnum
    {
        return match ($role) {
            CustomerSpaceMemberRoleEnum::Lead => NoteSpaceRoleEnum::Manager,
            CustomerSpaceMemberRoleEnum::Member => NoteSpaceRoleEnum::Editor,
        };
    }
}
