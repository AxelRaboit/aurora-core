<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Enum;

/**
 * Ce qu'on épingle dans un espace.
 *
 * **Trois genres et pas un champ libre**, parce que le genre décide de ce qui
 * est demandé, de ce qui est validé et de la façon dont la ligne se dessine :
 * un lien s'ouvre, un texte se lit, un contact se compose. Un seul genre
 * générique aurait donné une liste de titres avec un contenu qu'il aurait
 * fallu deviner à l'affichage.
 *
 * Il n'y a délibérément pas de genre « identifiants ». Un mot de passe rangé
 * dans un espace client est un mot de passe en clair dans une base, exporté
 * dans les sauvegardes et affiché à qui ouvre l'écran ; le champ manquant est
 * ce qui empêche l'habitude de se prendre. Un gestionnaire de mots de passe
 * fait cela, et le lien vers le coffre est, lui, un lien.
 *
 * Les valeurs sont persistées : on ajoute et on retire, on ne renomme pas.
 */
enum SpaceResourceKindEnum: string
{
    case Link = 'link';

    case Text = 'text';

    case Contact = 'contact';

    public function getLabelKey(): string
    {
        return match ($this) {
            self::Link => 'backend.studio.space_resources.kinds.link',
            self::Text => 'backend.studio.space_resources.kinds.text',
            self::Contact => 'backend.studio.space_resources.kinds.contact',
        };
    }

    /** Si ce genre porte une adresse, et donc si elle est exigée. */
    public function needsUrl(): bool
    {
        return self::Link === $this;
    }

    /** Si ce genre porte un corps, et donc s'il est exigé. */
    public function needsBody(): bool
    {
        return self::Text === $this;
    }
}
